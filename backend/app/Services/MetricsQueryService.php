<?php

namespace App\Services;

use App\Models\MetricSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class MetricsQueryService
{
    private const SUM_COLUMNS = ['spend', 'impressions', 'reach', 'clicks', 'conversions', 'leads', 'purchases', 'conversion_value'];

    public function summary(array $filters): array
    {
        $rows = $this->baseQuery($filters)->get();
        $currencies = $rows->groupBy('currency')->map(function ($currencyRows, $currency) {
            $totals = [];
            foreach (self::SUM_COLUMNS as $column) {
                $totals[$column] = (float) $currencyRows->sum($column);
            }
            return ['currency' => $currency, 'metrics' => MetricCalculator::derived($totals)];
        })->values();

        return $this->envelope($filters, $rows->max('collected_at'), ['currencies' => $currencies]);
    }

    public function trends(array $filters): array
    {
        $rows = $this->baseQuery($filters)->get();
        $series = $rows->groupBy(fn ($row) => $row->currency.'|'.$row->metric_date->toDateString())
            ->map(function ($dateRows, $key) {
                [$currency, $date] = explode('|', $key, 2);
                $totals = [];
                foreach (self::SUM_COLUMNS as $column) $totals[$column] = (float) $dateRows->sum($column);
                return ['date' => $date, 'currency' => $currency, 'metrics' => MetricCalculator::derived($totals)];
            })->sortBy('date')->values();

        return $this->envelope($filters, $rows->max('collected_at'), ['series' => $series]);
    }

    public function campaigns(array $filters): array
    {
        $filters['scope'] = 'campaign';
        $rows = $this->baseQuery($filters)->with('campaign:id,name,external_id,status,type')->get();
        $items = $rows->groupBy('campaign_id')->map(function ($campaignRows) {
            $first = $campaignRows->first();
            $totals = [];
            foreach (self::SUM_COLUMNS as $column) $totals[$column] = (float) $campaignRows->sum($column);
            return ['campaign' => $first->campaign, 'currency' => $first->currency, 'metrics' => MetricCalculator::derived($totals)];
        })->values();
        return $this->envelope($filters, $rows->max('collected_at'), ['campaigns' => $items]);
    }

    private function baseQuery(array $filters): Builder
    {
        return MetricSnapshot::query()
            ->whereHas('adAccount', fn (Builder $q) => $q->where('client_id', $filters['client_id']))
            ->where('scope', $filters['scope'] ?? 'account')
            ->when($filters['ad_account_id'] ?? null, fn (Builder $q, $id) => $q->where('ad_account_id', $id))
            ->whereBetween('metric_date', [$filters['start_date'], $filters['end_date']]);
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
