<?php

namespace App\Services;

use App\Models\Campaign;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class MetricsQueryService
{
    private const SUM_COLUMNS = ['spend', 'impressions', 'reach', 'clicks', 'conversions', 'leads', 'purchases', 'conversion_value'];

    public function summary(array $filters): array
    {
        $rows = $this->aggregateQuery($filters, ['metric_snapshots.currency'])->get();
        $currencies = $rows->map(fn ($row) => ['currency' => $row->currency, 'metrics' => MetricCalculator::derived($this->totals($row))])->values();

        return $this->envelope($filters, $rows->max('freshness'), ['currencies' => $currencies]);
    }

    public function trends(array $filters): array
    {
        $rows = $this->aggregateQuery($filters, ['metric_snapshots.currency', 'metric_snapshots.metric_date'])
            ->orderBy('metric_snapshots.metric_date')->get();
        $series = $rows->map(fn ($row) => [
            'date' => CarbonImmutable::parse($row->metric_date)->toDateString(),
            'currency' => $row->currency,
            'metrics' => MetricCalculator::derived($this->totals($row)),
        ])->values();

        return $this->envelope($filters, $rows->max('freshness'), ['series' => $series]);
    }

    public function campaigns(array $filters): array
    {
        $filters['scope'] = 'campaign';
        $rows = $this->aggregateQuery($filters, ['metric_snapshots.campaign_id', 'metric_snapshots.currency'])->get();
        $campaigns = Campaign::query()->whereIn('id', $rows->pluck('campaign_id')->filter()->unique())
            ->get(['id', 'name', 'external_id', 'status', 'type'])->keyBy('id');
        $items = $rows->map(fn ($row) => [
            'campaign' => $campaigns->get($row->campaign_id),
            'currency' => $row->currency,
            'metrics' => MetricCalculator::derived($this->totals($row)),
        ])->values();

        return $this->envelope($filters, $rows->max('freshness'), ['campaigns' => $items]);
    }

    private function aggregateQuery(array $filters, array $groups): Builder
    {
        $query = $this->baseQuery($filters)->select($groups)->selectRaw('MAX(metric_snapshots.collected_at) as freshness');
        foreach (self::SUM_COLUMNS as $column) {
            $query->selectRaw("SUM(metric_snapshots.{$column}) as {$column}");
        }

        return $query->groupBy($groups);
    }

    private function baseQuery(array $filters): Builder
    {
        return DB::table('metric_snapshots')
            ->join('ad_accounts', 'ad_accounts.id', '=', 'metric_snapshots.ad_account_id')
            ->where('ad_accounts.client_id', $filters['client_id'])
            ->where('metric_snapshots.scope', $filters['scope'] ?? 'account')
            ->when($filters['ad_account_id'] ?? null, fn (Builder $query, $id) => $query->where('metric_snapshots.ad_account_id', $id))
            ->whereBetween('metric_snapshots.metric_date', [$filters['start_date'], $filters['end_date']]);
    }

    private function totals(object $row): array
    {
        $totals = [];
        foreach (self::SUM_COLUMNS as $column) {
            $totals[$column] = (float) $row->{$column};
        }

        return $totals;
    }

    private function envelope(array $filters, mixed $freshness, array $payload): array
    {
        $client = $filters['client'];

        return array_merge([
            'scope' => ['client_id' => $client->id, 'ad_account_id' => $filters['ad_account_id'] ?? null, 'level' => $filters['scope'] ?? 'account'],
            'reporting_dates' => ['start' => $filters['start_date'], 'end' => $filters['end_date']],
            'timezone' => $client->timezone,
            'currency' => $client->currency,
            'freshness_timestamp' => $freshness ? CarbonImmutable::parse($freshness)->toIso8601String() : null,
            'conversion_mapping_version' => (int) ($client->conversionMappings()->max('version') ?? 1),
        ], $payload);
    }
}
