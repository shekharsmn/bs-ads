<?php

namespace App\Services;

use App\Models\PerformancePulse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PerformancePulseRenderer
{
    private const PERIOD_LABELS = [
        'yesterday' => 'Yesterday',
        'last_7_days' => 'Last 7 days',
        'month_to_date' => 'MTD',
        'last_30_days' => 'Last 30 days',
    ];

    public function __construct(private readonly PerformanceObjectiveClassifier $objectives) {}

    public function render(PerformancePulse $pulse, CarbonImmutable $reportingDate): array
    {
        $pulse->loadMissing(['client', 'adAccount', 'campaign', 'scopes.campaign']);
        $ranges = collect($pulse->periods)->mapWithKeys(fn (string $period) => [$period => $this->range($period, $reportingDate)]);
        $start = $ranges->min(fn (array $range) => $range['start']);
        $end = $ranges->max(fn (array $range) => $range['end']);
        $scopeReports = $this->selectedScopes($pulse)->map(function (array $scope) use ($pulse, $ranges, $start, $end) {
            $campaignRows = $this->campaignRows($pulse, $start, $end, $scope['campaign_id']);
            $accountRows = $scope['type'] === 'account' && $campaignRows->isEmpty() && $pulse->client->objective !== 'both'
                ? $this->accountRows($pulse, $start, $end)
                : collect();
            $classifications = $this->classifications($pulse, $campaignRows);
            $objectives = $pulse->client->objective === 'both' ? ['lead_gen', 'ecommerce'] : [$pulse->client->objective];
            $sections = [];
            $missing = [];

            foreach ($objectives as $objective) {
                $objectiveRows = $campaignRows->filter(fn ($row) => ($classifications[(int) $row->campaign_id] ?? $objective) === $objective);
                if ($campaignRows->isEmpty()) {
                    $objectiveRows = $accountRows;
                }
                $periods = [];
                foreach ($ranges as $key => $range) {
                    $rows = $objectiveRows->filter(function ($row) use ($range) {
                        $date = CarbonImmutable::parse($row->metric_date)->toDateString();

                        return $date >= $range['start'] && $date <= $range['end'];
                    });
                    if ($rows->isEmpty()) {
                        $missing[] = self::PERIOD_LABELS[$key];
                    }
                    $periods[$key] = [
                        'label' => self::PERIOD_LABELS[$key], 'start' => $range['start'], 'end' => $range['end'],
                        'metrics' => $this->totals($rows), 'campaigns' => $this->campaignBreakdown($rows),
                    ];
                }
                $spend = collect($periods)->sum(fn (array $period) => $period['metrics']['spend']);
                if ($pulse->client->objective !== 'both' || $spend > 0) {
                    $sections[] = ['objective' => $objective, 'periods' => $periods];
                }
            }

            $warning = null;
            if ($campaignRows->isEmpty() && $accountRows->isEmpty()) {
                $warning = 'No performance data is available for the selected periods.';
            } elseif ($missing) {
                $warning = 'Some selected periods have no reported rows: '.implode(', ', array_values(array_unique($missing))).'.';
            }

            return [
                ...$scope, 'sections' => $sections, 'warning' => $warning,
                'freshness_at' => $campaignRows->concat($accountRows)->max('freshness'),
            ];
        })->values();
        $freshness = $scopeReports->max('freshness_at');
        $warningParts = $scopeReports->pluck('warning')->filter()->unique()->values();
        if (filled($pulse->adAccount->last_error)) {
            $warningParts->push('The latest account collection reported an error.');
        }
        $warning = $warningParts->isEmpty() ? null : $warningParts->implode(' ');
        $text = $this->message($pulse, $scopeReports->all(), $warning, $freshness);
        $firstScope = $scopeReports->first();

        return [
            'title' => 'Performance Pulse · '.$pulse->client->name,
            'texts' => $this->split($text),
            'snapshot' => [
                'client' => $pulse->client->only(['id', 'name', 'objective']),
                'ad_account' => $pulse->adAccount->only(['id', 'name', 'platform', 'currency', 'timezone']),
                'campaign' => count($scopeReports) === 1 && $firstScope['campaign_id'] ? ['id' => $firstScope['campaign_id'], 'name' => $firstScope['label']] : null,
                'scopes' => $scopeReports->map(fn (array $scope) => collect($scope)->except(['warning', 'freshness_at'])->all())->all(),
                'sections' => $firstScope['sections'] ?? [],
                'generated_at' => now()->toIso8601String(),
            ],
            'warning' => $warning,
            'freshness_at' => $freshness,
        ];
    }

    private function selectedScopes(PerformancePulse $pulse): Collection
    {
        if ($pulse->scopes->isNotEmpty()) {
            return $pulse->scopes->map(fn ($scope) => [
                'type' => $scope->campaign_id ? 'campaign' : 'account',
                'campaign_id' => $scope->campaign_id ? (int) $scope->campaign_id : null,
                'label' => $scope->campaign?->name ?: $pulse->adAccount->name,
            ]);
        }

        return collect([[
            'type' => $pulse->campaign_id ? 'campaign' : 'account',
            'campaign_id' => $pulse->campaign_id ? (int) $pulse->campaign_id : null,
            'label' => $pulse->campaign?->name ?: $pulse->adAccount->name,
        ]]);
    }

    private function range(string $period, CarbonImmutable $reportingDate): array
    {
        $end = $reportingDate->toDateString();
        $start = match ($period) {
            'yesterday' => $reportingDate,
            'last_7_days' => $reportingDate->subDays(6),
            'month_to_date' => $reportingDate->startOfMonth(),
            'last_30_days' => $reportingDate->subDays(29),
        };

        return ['start' => $start->toDateString(), 'end' => $end];
    }

    private function campaignRows(PerformancePulse $pulse, string $start, string $end, ?int $campaignId = null): Collection
    {
        return DB::table('metric_snapshots')
            ->join('campaigns', 'campaigns.id', '=', 'metric_snapshots.campaign_id')
            ->where('metric_snapshots.ad_account_id', $pulse->ad_account_id)
            ->where('metric_snapshots.scope', 'campaign')
            ->when($campaignId, fn ($query, $id) => $query->where('metric_snapshots.campaign_id', $id))
            ->whereDate('metric_snapshots.metric_date', '>=', $start)->whereDate('metric_snapshots.metric_date', '<=', $end)
            ->groupBy('metric_snapshots.campaign_id', 'campaigns.name', 'campaigns.type', 'metric_snapshots.metric_date')
            ->orderBy('campaigns.name')
            ->get([
                'metric_snapshots.campaign_id', 'campaigns.name as campaign_name', 'campaigns.type as campaign_type', 'metric_snapshots.metric_date',
                DB::raw('SUM(metric_snapshots.spend) as spend'), DB::raw('SUM(metric_snapshots.leads) as leads'),
                DB::raw('SUM(metric_snapshots.purchases) as purchases'), DB::raw('SUM(metric_snapshots.conversion_value) as conversion_value'),
                DB::raw('MAX(metric_snapshots.collected_at) as freshness'),
            ]);
    }

    private function accountRows(PerformancePulse $pulse, string $start, string $end): Collection
    {
        return DB::table('metric_snapshots')->where('ad_account_id', $pulse->ad_account_id)->where('scope', 'account')
            ->whereDate('metric_date', '>=', $start)->whereDate('metric_date', '<=', $end)->groupBy('metric_date')->get([
                'metric_date', DB::raw('NULL as campaign_id'), DB::raw("'Account total' as campaign_name"), DB::raw('NULL as campaign_type'),
                DB::raw('SUM(spend) as spend'), DB::raw('SUM(leads) as leads'), DB::raw('SUM(purchases) as purchases'),
                DB::raw('SUM(conversion_value) as conversion_value'), DB::raw('MAX(collected_at) as freshness'),
            ]);
    }

    private function classifications(PerformancePulse $pulse, Collection $rows): array
    {
        if ($pulse->client->objective !== 'both') {
            return [];
        }

        return $rows->groupBy('campaign_id')->map(function (Collection $campaignRows): string {
            $row = (object) [
                'campaign_type' => $campaignRows->first()->campaign_type,
                'leads' => $campaignRows->sum('leads'),
                'purchases' => $campaignRows->sum('purchases'),
                'conversion_value' => $campaignRows->sum('conversion_value'),
            ];

            return $this->objectives->classify($row, 'both') === 'sales' ? 'ecommerce' : 'lead_gen';
        })->all();
    }

    private function totals(Collection $rows): array
    {
        $spend = (float) $rows->sum('spend');
        $leads = (float) $rows->sum('leads');
        $purchases = (float) $rows->sum('purchases');
        $revenue = (float) $rows->sum('conversion_value');

        return [
            'spend' => $spend, 'leads' => $leads, 'purchases' => $purchases, 'revenue' => $revenue,
            'cpl' => $leads > 0 ? round($spend / $leads, 2) : 0,
            'roas' => $spend > 0 ? round($revenue / $spend, 2) : 0,
        ];
    }

    private function campaignBreakdown(Collection $rows): array
    {
        return $rows->filter(fn ($row) => $row->campaign_id)->groupBy('campaign_id')->map(function (Collection $campaignRows): array {
            return ['id' => (int) $campaignRows->first()->campaign_id, 'name' => $campaignRows->first()->campaign_name, 'metrics' => $this->totals($campaignRows)];
        })->filter(fn (array $campaign) => $campaign['metrics']['spend'] > 0)->sortByDesc(fn (array $campaign) => $campaign['metrics']['spend'])->values()->all();
    }

    private function message(PerformancePulse $pulse, array $scopeReports, ?string $warning, mixed $freshness): string
    {
        $platform = $pulse->adAccount->platform instanceof \BackedEnum ? $pulse->adAccount->platform->value : $pulse->adAccount->platform;
        $lines = ['*'.$pulse->client->name.'*', '', 'Account: '.$pulse->adAccount->name, 'Type: '.ucfirst((string) $platform)];
        foreach ($scopeReports as $scopeIndex => $scopeReport) {
            $sections = $scopeReport['sections'];
            $lines[] = '';
            $lines[] = count($scopeReports) > 1
                ? '*Scope '.($scopeIndex + 1).' of '.count($scopeReports).': '.$scopeReport['label'].'*'
                : 'For: '.$scopeReport['label'];
            foreach ($sections as $section) {
                if (count($sections) > 1) {
                    $lines[] = '';
                    $lines[] = '*'.($section['objective'] === 'ecommerce' ? 'E-commerce' : 'Lead generation').'*';
                }
                foreach (array_values($section['periods']) as $index => $period) {
                    $lines[] = '';
                    $lines[] = ($index + 1).'. *Period: '.$this->periodHeading($period).'*';
                    $lines = [...$lines, ...$this->metricLines($section['objective'], $period['metrics'], $pulse->adAccount->currency)];
                    if ($pulse->include_campaign_breakdown && $scopeReport['type'] === 'account' && $period['campaigns']) {
                        $lines[] = '';
                        $lines[] = '*Campaign breakdown*';
                        foreach ($period['campaigns'] as $campaign) {
                            $lines[] = '';
                            $lines[] = $campaign['name'];
                            $lines = [...$lines, ...$this->metricLines($section['objective'], $campaign['metrics'], $pulse->adAccount->currency)];
                        }
                    }
                }
            }
            if (! $sections) {
                $lines[] = '';
                $lines[] = 'No positive spend was reported for the selected periods.';
            }
        }
        if ($warning) {
            $lines[] = '';
            $lines[] = '⚠ '.$warning.($freshness ? ' Last refreshed: '.CarbonImmutable::parse($freshness)->timezone($pulse->adAccount->timezone)->format('d M Y, H:i T').'.' : '');
        }

        return implode("\n", $lines);
    }

    private function periodHeading(array $period): string
    {
        $start = CarbonImmutable::parse($period['start']);
        $end = CarbonImmutable::parse($period['end']);
        $dates = $start->equalTo($end) ? $end->format('M j') : $start->format('M j').' to '.$end->format('M j');

        return $period['label'].' ('.$dates.')';
    }

    private function metricLines(string $objective, array $metrics, string $currency): array
    {
        if ($objective === 'ecommerce') {
            return ['Spend: '.$this->money($metrics['spend'], $currency), 'Orders: '.$this->number($metrics['purchases']), 'Revenue: '.$this->money($metrics['revenue'], $currency), 'ROAS: '.number_format($metrics['roas'], 2, '.', '')];
        }

        return ['Leads: '.$this->number($metrics['leads']), 'CPL: '.($metrics['leads'] > 0 ? $this->money($metrics['cpl'], $currency) : '0'), 'Spend: '.$this->money($metrics['spend'], $currency)];
    }

    private function money(float $amount, string $currency): string
    {
        $symbols = ['INR' => '₹', 'USD' => '$', 'GBP' => '£', 'EUR' => '€'];

        return ($symbols[strtoupper($currency)] ?? strtoupper($currency).' ').number_format($amount, 2);
    }

    private function number(float $value): string
    {
        return fmod($value, 1.0) === 0.0 ? number_format($value, 0) : number_format($value, 2);
    }

    private function split(string $text, int $limit = 28000): array
    {
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }
        $parts = [];
        $current = '';
        foreach (explode("\n", $text) as $line) {
            if ($current !== '' && mb_strlen($current."\n".$line) > $limit) {
                $parts[] = $current;
                $current = '*Performance Pulse · continued*'."\n\n".$line;
            } else {
                $current .= ($current === '' ? '' : "\n").$line;
            }
        }
        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }
}
