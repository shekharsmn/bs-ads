<?php

namespace App\Services;

final class MetricCalculator
{
    public static function derived(array $totals): array
    {
        $spend = (float) ($totals['spend'] ?? 0);
        $impressions = (float) ($totals['impressions'] ?? 0);
        $reach = (float) ($totals['reach'] ?? 0);
        $clicks = (float) ($totals['clicks'] ?? 0);
        $conversions = (float) ($totals['conversions'] ?? 0);
        $leads = (float) ($totals['leads'] ?? 0);
        $value = (float) ($totals['conversion_value'] ?? 0);

        return array_merge($totals, [
            'ctr' => self::divide($clicks * 100, $impressions),
            'cpc' => self::divide($spend, $clicks),
            'cpm' => self::divide($spend * 1000, $impressions),
            'cpa' => self::divide($spend, $conversions),
            'cpl' => self::divide($spend, $leads),
            'conversion_rate' => self::divide($conversions * 100, $clicks),
            'roas' => self::divide($value, $spend),
            'frequency' => self::divide($impressions, $reach),
        ]);
    }

    public static function divide(float $numerator, float $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator, 6) : null;
    }
}
