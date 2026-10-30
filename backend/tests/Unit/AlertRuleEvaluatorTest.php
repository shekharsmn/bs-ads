<?php

namespace Tests\Unit;

use App\Services\AlertRuleEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AlertRuleEvaluatorTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_rule_operators(string $operator, ?float $threshold, array $current, array $baseline, array $context, bool $expected): void
    {
        $result = (new AlertRuleEvaluator)->evaluate($operator, $threshold, $current, $baseline, $context);
        $this->assertSame($expected, $result['breached']);
    }

    public static function cases(): array
    {
        return [
            'below' => ['below', 2.0, ['roas' => 1.5], [], ['metric' => 'roas'], true],
            'above' => ['above', 100.0, ['cpl' => 125], [], ['metric' => 'cpl'], true],
            'drop' => ['percentage_drop', 20.0, ['ctr' => 1.5], ['ctr' => 2.0], ['metric' => 'ctr'], true],
            'no conversions guard met' => ['no_conversions_with_spend', null, ['spend' => 250, 'conversions' => 0], [], ['metric' => 'conversions', 'minimum_spend' => 100], true],
            'no conversions guard not met' => ['no_conversions_with_spend', null, ['spend' => 50, 'conversions' => 0], [], ['metric' => 'conversions', 'minimum_spend' => 100], false],
            'over pacing' => ['budget_over_pacing', 110, ['spend' => 600], [], ['metric' => 'spend', 'monthly_budget' => 1000, 'day_of_month' => 15, 'days_in_month' => 30], true],
            'stale' => ['data_stale', 3, [], [], ['metric' => 'spend', 'freshness_hours' => 4], true],
            'connection error' => ['connection_error', null, [], [], ['metric' => 'spend', 'connection_error' => true], true],
        ];
    }

    public function test_zero_denominators_return_null(): void
    {
        $this->assertNull(\App\Services\MetricCalculator::derived(['spend' => 10, 'clicks' => 0])['cpc']);
    }

    public function test_evaluation_preserves_observed_and_baseline_spend(): void
    {
        $result = (new AlertRuleEvaluator)->evaluate(
            'no_conversions_with_spend',
            null,
            ['spend' => 250, 'conversions' => 0],
            ['spend' => 175, 'conversions' => 3],
            ['metric' => 'conversions', 'minimum_spend' => 100],
        );

        $this->assertSame(250.0, $result['observed_spend']);
        $this->assertSame(175.0, $result['baseline_spend']);
    }

    public function test_percentage_increase_preserves_metric_values_and_records_change_separately(): void
    {
        $result = (new AlertRuleEvaluator)->evaluate(
            'percentage_increase',
            25,
            ['cpl' => 1878.6949949665],
            ['cpl' => 1327.597920],
            ['metric' => 'cpl'],
        );

        $this->assertTrue($result['breached']);
        $this->assertSame(1878.6949949665, $result['observed']);
        $this->assertSame(1327.597920, $result['baseline']);
        $this->assertEqualsWithDelta(41.510842, $result['comparison'], 0.000001);
        $this->assertSame(25.0, $result['threshold']);
    }
}
