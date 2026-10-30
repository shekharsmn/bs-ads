<?php

namespace App\Services;

use App\Models\AdAccount;
use App\Models\AlertRule;
use App\Models\AlertRuleState;
use App\Models\Campaign;
use App\Models\MetricSnapshot;
use Illuminate\Support\Collection;

class AlertEngine
{
    public function __construct(private readonly AlertRuleEvaluator $evaluator, private readonly IncidentService $incidents) {}

    public function evaluateAccount(AdAccount $account): void
    {
        $this->evaluateScope($account, null);
        foreach ($account->campaigns()->whereIn('status', ['ENABLED', 'ACTIVE', 'enabled', 'active'])->get() as $campaign) {
            $this->evaluateScope($account, $campaign);
        }
    }

    private function evaluateScope(AdAccount $account, ?Campaign $campaign): void
    {
        $rules = $this->effectiveRules($account, $campaign);
        foreach ($rules as $rule) {
            $days = max(1, $rule->window_days);
            // Platform metrics for the account's current local date are still changing.
            // Anchor alert windows to yesterday so every included date is complete.
            $end = now($account->timezone)->subDay()->startOfDay();
            $currentStart = in_array($rule->operator, ['budget_under_pacing', 'budget_over_pacing'], true)
                ? $end->copy()->startOfMonth()
                : $end->copy()->subDays($days - 1);
            $currentRows = $this->metricRows($account, $campaign, $currentStart, $end);
            $baselineRows = $this->metricRows($account, $campaign, $end->copy()->subDays(($days * 2) - 1), $end->copy()->subDays($days));
            $current = $this->aggregate($currentRows);
            $baseline = $this->aggregate($baselineRows);
            $latest = $currentRows->max('collected_at');
            $result = $this->evaluator->evaluate($rule->operator, $rule->threshold === null ? null : (float) $rule->threshold, $current, $baseline, [
                'metric' => $rule->metric,
                'minimum_spend' => $rule->minimum_spend,
                'minimum_impressions' => $rule->minimum_impressions,
                'monthly_budget' => $account->monthly_budget,
                'day_of_month' => $end->day,
                'days_in_month' => $end->daysInMonth,
                'freshness_hours' => $latest ? now()->diffInHours($latest) : INF,
                'connection_error' => $account->connection->status->value === 'error',
            ]);
            $result = array_merge($result, [
                'currency' => $account->currency,
                'observed_period_start' => $currentStart->copy()->startOfDay()->utc()->toIso8601String(),
                'observed_period_end' => $end->copy()->endOfDay()->utc()->toIso8601String(),
                'baseline_period_start' => $end->copy()->subDays(($days * 2) - 1)->startOfDay()->utc()->toIso8601String(),
                'baseline_period_end' => $end->copy()->subDays($days)->endOfDay()->utc()->toIso8601String(),
            ]);
            $this->applyResult($rule, $account, $campaign, $result);
        }
    }

    private function effectiveRules(AdAccount $account, ?Campaign $campaign): Collection
    {
        $query = AlertRule::query()->where('enabled', true)->where(function ($q) use ($account, $campaign) {
            $q->where(function ($global) {
                $global->whereNull('client_id')->whereNull('ad_account_id')->whereNull('campaign_id');
            })
                ->orWhere(fn ($client) => $client->where('client_id', $account->client_id)->whereNull('ad_account_id')->whereNull('campaign_id'))
                ->orWhere(fn ($aq) => $aq->where('ad_account_id', $account->id)->whereNull('campaign_id'));
            if ($campaign) {
                $q->orWhere('campaign_id', $campaign->id);
            }
        })->get();

        return $query->sortBy(fn (AlertRule $rule) => $rule->campaign_id ? 4 : ($rule->ad_account_id ? 3 : ($rule->client_id ? 2 : 1)))
            ->keyBy(fn (AlertRule $rule) => $rule->alert_template_id ? 'template:'.$rule->alert_template_id : $rule->metric.'|'.$rule->operator)
            ->values();
    }

    private function applyResult(AlertRule $rule, AdAccount $account, ?Campaign $campaign, array $result): void
    {
        $scopeKey = $campaign ? 'campaign:'.$campaign->id : 'account:'.$account->id;
        $state = AlertRuleState::firstOrCreate(['alert_rule_id' => $rule->id, 'scope_key' => $scopeKey]);
        $critical = in_array($rule->operator, ['connection_error', 'data_stale'], true);
        if ($result['breached']) {
            $state->failure_count++;
            $state->healthy_count = 0;
            if ($critical || $state->failure_count >= 2) {
                $state->currently_breaching = true;
                $this->incidents->trigger($rule, $account, $campaign, $result);
            }
        } else {
            $state->healthy_count++;
            $state->failure_count = 0;
            if ($state->currently_breaching && $state->healthy_count >= 2) {
                $state->currently_breaching = false;
                $this->incidents->recover($rule, $account, $campaign);
            }
        }
        $state->last_evaluated_at = now();
        $state->last_result = $result;
        $state->save();
    }

    private function metricRows(AdAccount $account, ?Campaign $campaign, $start, $end): Collection
    {
        return MetricSnapshot::query()->where('ad_account_id', $account->id)
            ->where('scope', $campaign ? 'campaign' : 'account')
            ->when($campaign, fn ($q) => $q->where('campaign_id', $campaign->id))
            ->whereDate('metric_date', '>=', $start->toDateString())
            ->whereDate('metric_date', '<=', $end->toDateString())->get();
    }

    private function aggregate(Collection $rows): array
    {
        $base = [];
        foreach (['spend', 'impressions', 'reach', 'clicks', 'conversions', 'leads', 'purchases', 'conversion_value'] as $key) {
            $base[$key] = (float) $rows->sum($key);
        }

        return MetricCalculator::derived($base);
    }
}
