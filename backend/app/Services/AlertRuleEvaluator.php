<?php

namespace App\Services;

use App\Enums\AlertOperator;

class AlertRuleEvaluator
{
    public function evaluate(string $operator, ?float $threshold, array $current, array $baseline = [], array $context = []): array
    {
        $metric = (string) ($context['metric'] ?? 'spend');
        $observed = array_key_exists($metric, $current) && $current[$metric] !== null ? (float) $current[$metric] : null;
        $base = array_key_exists($metric, $baseline) && $baseline[$metric] !== null ? (float) $baseline[$metric] : null;
        $spend = (float) ($current['spend'] ?? 0);
        $breached = false;
        $reason = '';

        switch (AlertOperator::from($operator)) {
            case AlertOperator::Below:
                $breached = $observed !== null && $threshold !== null && $observed < $threshold;
                $reason = "$metric is below threshold";
                break;
            case AlertOperator::Above:
                $breached = $observed !== null && $threshold !== null && $observed > $threshold;
                $reason = "$metric is above threshold";
                break;
            case AlertOperator::PercentageDrop:
                $change = ($base !== null && $base != 0.0 && $observed !== null) ? (($base - $observed) / abs($base)) * 100 : null;
                $breached = $change !== null && $threshold !== null && $change >= $threshold;
                $observed = $change;
                $reason = "$metric deteriorated versus the preceding baseline";
                break;
            case AlertOperator::PercentageIncrease:
                $change = ($base !== null && $base != 0.0 && $observed !== null) ? (($observed - $base) / abs($base)) * 100 : null;
                $breached = $change !== null && $threshold !== null && $change >= $threshold;
                $observed = $change;
                $reason = "$metric increased versus the preceding baseline";
                break;
            case AlertOperator::NoSpend:
                $breached = $spend <= 0;
                $observed = $spend;
                $reason = 'No spend was recorded';
                break;
            case AlertOperator::NoImpressions:
                $observed = (float) ($current['impressions'] ?? 0);
                $breached = $observed <= 0;
                $reason = 'No impressions were recorded';
                break;
            case AlertOperator::NoConversionsWithSpend:
                $observed = (float) ($current['conversions'] ?? 0);
                $breached = $spend >= (float) ($context['minimum_spend'] ?? 0) && $observed <= 0;
                $reason = 'Spend was recorded without conversions';
                break;
            case AlertOperator::BudgetUnderPacing:
            case AlertOperator::BudgetOverPacing:
                $budget = (float) ($context['monthly_budget'] ?? 0);
                $day = max(1, (int) ($context['day_of_month'] ?? now()->day));
                $days = max($day, (int) ($context['days_in_month'] ?? now()->daysInMonth));
                $expected = $budget * ($day / $days);
                $pacing = $expected > 0 ? ($spend / $expected) * 100 : null;
                $observed = $pacing;
                $breached = $pacing !== null && $threshold !== null && ($operator === AlertOperator::BudgetUnderPacing->value ? $pacing < $threshold : $pacing > $threshold);
                $reason = 'Month-to-date spend is outside the pacing threshold';
                break;
            case AlertOperator::DataStale:
                $observed = (float) ($context['freshness_hours'] ?? INF);
                $breached = $threshold !== null && $observed > $threshold;
                $reason = 'Metric data is stale';
                break;
            case AlertOperator::ConnectionError:
                $breached = (bool) ($context['connection_error'] ?? false);
                $observed = $breached ? 1.0 : 0.0;
                $reason = 'The platform connection is unhealthy';
                break;
        }

        $minimumImpressions = (int) ($context['minimum_impressions'] ?? 0);
        if ($minimumImpressions > 0 && (int) ($current['impressions'] ?? 0) < $minimumImpressions) $breached = false;

        return ['breached' => $breached, 'observed' => $observed, 'baseline' => $base, 'threshold' => $threshold, 'reason' => $reason];
    }
}
