<?php

namespace App\Services;

use App\Models\AdAccount;
use App\Models\DataQualityIssue;
use Carbon\CarbonPeriod;

class DataQualityMonitor
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public function inspect(AdAccount $account): array
    {
        $detected = [];
        $now = now();
        if (! $account->last_collected_at || $account->last_collected_at->lt($now->copy()->subHours(2))) {
            $detected[] = $this->issue($account, 'stale_collection', 'critical', 'Metric collection is stale', ['last_collected_at' => optional($account->last_collected_at)->toIso8601String()], 'Check the connection and retry collection.');
        }
        if ($account->status !== 'active' || $account->last_error) {
            $detected[] = $this->issue($account, 'account_access', 'critical', 'Account access is unhealthy', ['status' => $account->status, 'error' => mb_substr((string) $account->last_error, 0, 500)], 'Reconnect the upstream account and verify permissions.');
        }
        if ($account->client && ($account->currency !== $account->client->currency || $account->timezone !== $account->client->timezone)) {
            $detected[] = $this->issue($account, 'locale_mismatch', 'warning', 'Currency or timezone differs from the client', ['account_currency' => $account->currency, 'client_currency' => $account->client->currency, 'account_timezone' => $account->timezone, 'client_timezone' => $account->client->timezone], 'Confirm whether reporting should use account or client locale settings.');
        }

        $dates = $account->metrics()->where('scope', 'account')->whereDate('metric_date', '>=', now()->subDays(7)->toDateString())->pluck('metric_date')->map(fn ($date) => $date->toDateString())->unique();
        $expected = collect(CarbonPeriod::create(now()->subDays(6)->startOfDay(), now()->startOfDay()))->map(fn ($date) => $date->toDateString());
        $missing = $expected->diff($dates)->values();
        if ($dates->isNotEmpty() && $missing->isNotEmpty()) {
            $detected[] = $this->issue($account, 'missing_days', 'warning', 'Daily metric snapshots are missing', ['missing_dates' => $missing->all()], 'Retry collection for the missing dates.');
        }

        $daily = $account->metrics()->whereDate('metric_date', '>=', now()->subDays(8)->toDateString())->selectRaw('metric_date, COUNT(*) as rows_count, SUM(conversions) as conversions')->groupBy('metric_date')->orderBy('metric_date')->get();
        if ($daily->count() >= 4) {
            $latest = $daily->last();
            $baseline = $daily->slice(0, -1)->pluck('rows_count')->sort()->values();
            $median = (float) $baseline->get((int) floor(($baseline->count() - 1) / 2), 0);
            if ($median > 0 && abs(((float) $latest->rows_count - $median) / $median) > .5) {
                $detected[] = $this->issue($account, 'row_count_shift', 'warning', 'Snapshot row count changed unexpectedly', ['latest' => (int) $latest->rows_count, 'median' => $median], 'Check upstream account scope and collection completeness.');
            }
            if ((float) $latest->conversions === 0.0 && $daily->slice(0, -1)->sum('conversions') > 0) {
                $detected[] = $this->issue($account, 'unexpected_zero', 'warning', 'Conversions unexpectedly dropped to zero', ['metric_date' => (string) $latest->metric_date], 'Verify conversion mapping and upstream tracking.');
            }
            if ((float) $latest->conversions > 0 && $account->client->conversionMappings()->where('platform', $account->platform->value)->where('active', true)->doesntExist()) {
                $detected[] = $this->issue($account, 'unmapped_conversions', 'warning', 'Conversions have no active mapping', ['metric_date' => (string) $latest->metric_date, 'platform' => $account->platform->value], 'Map upstream conversion actions to a canonical client metric.');
            }
        }

        $activeKeys = collect($detected)->pluck('issue_key');
        DataQualityIssue::where('ad_account_id', $account->id)->where('status', 'open')->whereNotIn('issue_key', $activeKeys)->update(['status' => 'resolved', 'resolved_at' => now()]);

        return $detected;
    }

    private function issue(AdAccount $account, string $type, string $severity, string $title, array $evidence, string $remediation): DataQualityIssue
    {
        $key = $account->id.':'.$type;
        $issue = DataQualityIssue::firstOrNew(['issue_key' => $key]);
        $wasResolved = $issue->exists && $issue->status === 'resolved';
        $issue->fill([
            'client_id' => $account->client_id, 'ad_account_id' => $account->id, 'type' => $type,
            'severity' => $severity, 'status' => 'open', 'title' => $title, 'summary' => $title,
            'evidence' => $evidence, 'remediation' => $remediation,
            'first_detected_at' => $issue->first_detected_at ?: now(),
            'last_detected_at' => now(), 'resolved_at' => null,
        ]);
        $issue->save();
        if ($issue->wasRecentlyCreated || $wasResolved) {
            $occurrence = $wasResolved ? $issue->id.':reopened:'.$issue->last_detected_at->format('YmdHis.u') : (string) $issue->id;
            $this->notifications->dispatch($account->client_id, 'data_quality', ['title' => $title, 'text' => $title.' for '.$account->name, 'issue_id' => $issue->id, 'severity' => $severity], $occurrence);
        }

        return $issue;
    }
}
