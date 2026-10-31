<?php

namespace App\Services;

use App\Models\AdAccount;
use App\Models\AdGroup;
use App\Models\Campaign;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PerformanceReadService
{
    private const SUM_COLUMNS = ['spend', 'impressions', 'reach', 'clicks', 'conversions', 'leads', 'purchases', 'conversion_value'];

    public function __construct(private readonly PerformanceObjectiveClassifier $objectives) {}

    public function accounts(User $user): array
    {
        $clientIds = ClientAccess::clientsFor($user)->pluck('id');
        $accounts = AdAccount::query()->with('client:id,name,objective')->whereIn('client_id', $clientIds)
            ->where('status', 'active')->orderBy('name')->get();

        return $accounts->groupBy(fn (AdAccount $account) => $account->client_id.'|'.$account->platform->value.'|'.$account->currency)
            ->map(function ($platformAccounts): array {
                /** @var AdAccount $first */
                $first = $platformAccounts->first();
                $classifiedRows = $platformAccounts->flatMap(fn (AdAccount $account) => $this->commandCenterRows($account));
                $thirtyDaySpend = $classifiedRows->groupBy('result_type')->map->sum('spend');
                $resultType = $this->objectives->winning($thirtyDaySpend, (string) $first->client?->objective);
                $windows = collect(['yesterday' => 1, 'last_7_days' => 7, 'last_30_days' => 30])
                    ->mapWithKeys(function (int $days, string $key) use ($classifiedRows, $resultType) {
                        $rows = $classifiedRows->where('result_type', $resultType)->filter(fn (array $row) => $row['days_ago'] < $days);
                        $spend = (float) $rows->sum('spend');
                        $results = (float) $rows->sum('results');

                        return [$key => [
                            'spend' => $spend,
                            'results' => $results,
                            'cpr' => MetricCalculator::divide($spend, $results),
                        ]];
                    });

                return [
                    'id' => $first->id,
                    'name' => $first->name,
                    'external_id' => $first->external_id,
                    'account_ids' => $platformAccounts->pluck('id')->values(),
                    'account_count' => $platformAccounts->count(),
                    'platform' => $first->platform->value,
                    'currency' => $first->currency,
                    'timezone' => $first->timezone,
                    'client' => $first->client,
                    'result_type' => $resultType,
                    'freshness_timestamp' => $platformAccounts->pluck('last_collected_at')->filter()->min()?->toIso8601String(),
                    'windows' => $windows,
                ];
            })->sortBy(fn (array $row) => strtolower(($row['client']['name'] ?? '').' '.$row['platform']))
            ->values()->all();
    }

    private function commandCenterRows(AdAccount $account)
    {
        $yesterday = CarbonImmutable::now($account->timezone)->subDay()->startOfDay();
        $start = $yesterday->subDays(29)->toDateString();
        $end = $yesterday->toDateString();
        $campaignRows = DB::table('metric_snapshots')
            ->leftJoin('campaigns', 'campaigns.id', '=', 'metric_snapshots.campaign_id')
            ->where('metric_snapshots.ad_account_id', $account->id)
            ->where('metric_snapshots.scope', 'campaign')
            ->whereDate('metric_snapshots.metric_date', '>=', $start)
            ->whereDate('metric_snapshots.metric_date', '<=', $end)
            ->get(['metric_snapshots.*', 'campaigns.type as campaign_type']);
        $campaignDates = $campaignRows->pluck('metric_date')->map(fn ($date) => CarbonImmutable::parse($date)->toDateString())->unique();
        $accountRows = DB::table('metric_snapshots')
            ->where('ad_account_id', $account->id)->where('scope', 'account')
            ->whereDate('metric_date', '>=', $start)->whereDate('metric_date', '<=', $end)->get()
            ->reject(fn ($row) => $campaignDates->contains(CarbonImmutable::parse($row->metric_date)->toDateString()));

        return $campaignRows->concat($accountRows)->map(function ($row) use ($account, $yesterday): array {
            $resultType = $this->objectives->classify($row, (string) $account->client?->objective);
            $leads = (float) ($row->leads ?? 0);
            $purchases = (float) ($row->purchases ?? 0);
            $results = $resultType === 'sales' ? $purchases : $leads;
            if ($results <= 0 && $leads <= 0 && $purchases <= 0) {
                $results = (float) ($row->conversions ?? 0);
            }

            return [
                'result_type' => $resultType,
                'spend' => (float) ($row->spend ?? 0),
                'results' => $results,
                'days_ago' => CarbonImmutable::parse($row->metric_date, $account->timezone)->startOfDay()->diffInDays($yesterday),
            ];
        });
    }

    public function overview(AdAccount $account, string $start, string $end): array
    {
        $summaryRow = $this->aggregate($account->id, 'account', $start, $end)->first();
        $seriesRows = $this->aggregate($account->id, 'account', $start, $end, ['metric_snapshots.metric_date'])
            ->orderBy('metric_snapshots.metric_date')->get();

        return [
            'account' => $this->accountPayload($account),
            'reporting_dates' => ['start' => $start, 'end' => $end],
            'freshness_timestamp' => $summaryRow?->freshness ? CarbonImmutable::parse($summaryRow->freshness)->toIso8601String() : null,
            'metrics' => $summaryRow ? $this->metrics($this->rowTotals($summaryRow)) : $this->metrics([]),
            'series' => $seriesRows->map(fn ($row) => [
                'date' => CarbonImmutable::parse($row->metric_date)->toDateString(),
                'metrics' => $this->metrics($this->rowTotals($row)),
            ])->values(),
        ];
    }

    public function campaigns(AdAccount $account, string $start, string $end): array
    {
        $rows = $this->aggregate($account->id, 'campaign', $start, $end, ['metric_snapshots.campaign_id'])->get();
        $models = Campaign::query()->whereIn('id', $rows->pluck('campaign_id')->filter())->get()->keyBy('id');

        return ['campaigns' => $rows->map(fn ($row) => [
            'campaign' => $models->get($row->campaign_id),
            'metrics' => $this->metrics($this->rowTotals($row)),
        ])->filter(fn ($row) => $row['campaign'])->sortByDesc('metrics.spend')->values()];
    }

    public function adGroups(AdAccount $account, Campaign $campaign, string $start, string $end): array
    {
        $rows = $this->aggregate($account->id, 'ad_group', $start, $end, ['metric_snapshots.ad_group_id'])
            ->where('metric_snapshots.campaign_id', $campaign->id)->get();
        $models = AdGroup::query()->whereIn('id', $rows->pluck('ad_group_id')->filter())->get()->keyBy('id');

        return [
            'campaign' => $campaign,
            'metrics' => $this->entityMetrics($account->id, 'campaign', 'campaign_id', $campaign->id, $start, $end),
            'ad_groups' => $rows->map(fn ($row) => [
                'ad_group' => $models->get($row->ad_group_id),
                'metrics' => $this->metrics($this->rowTotals($row)),
            ])->filter(fn ($row) => $row['ad_group'])->sortByDesc('metrics.spend')->values(),
        ];
    }

    public function ads(AdAccount $account, Campaign $campaign, AdGroup $adGroup, string $start, string $end): array
    {
        $rows = $this->aggregate($account->id, 'ad', $start, $end, ['metric_snapshots.ad_id'])
            ->where('metric_snapshots.campaign_id', $campaign->id)
            ->where('metric_snapshots.ad_group_id', $adGroup->id)->get();
        $models = \App\Models\Ad::query()->whereIn('id', $rows->pluck('ad_id')->filter())->get()->keyBy('id');

        return [
            'campaign' => $campaign,
            'ad_group' => $adGroup,
            'metrics' => $this->entityMetrics($account->id, 'ad_group', 'ad_group_id', $adGroup->id, $start, $end),
            'ads' => $rows->map(fn ($row) => [
                'ad' => $models->get($row->ad_id),
                'metrics' => $this->metrics($this->rowTotals($row)),
            ])->filter(fn ($row) => $row['ad'])->sortByDesc('metrics.spend')->values(),
        ];
    }

    private function entityMetrics(int $accountId, string $scope, string $column, int $id, string $start, string $end): array
    {
        $row = $this->aggregate($accountId, $scope, $start, $end)->where("metric_snapshots.{$column}", $id)->first();

        return $row ? $this->metrics($this->rowTotals($row)) : $this->metrics([]);
    }

    private function aggregate(int $accountId, string $scope, string $start, string $end, array $groups = []): Builder
    {
        $query = DB::table('metric_snapshots')->where('ad_account_id', $accountId)->where('scope', $scope)
            ->whereDate('metric_date', '>=', $start)->whereDate('metric_date', '<=', $end)
            ->select($groups)->selectRaw('MAX(collected_at) as freshness');
        foreach (self::SUM_COLUMNS as $column) {
            $query->selectRaw("SUM({$column}) as {$column}");
        }

        return $groups ? $query->groupBy($groups) : $query;
    }

    private function rowTotals(object $row): array
    {
        return collect(self::SUM_COLUMNS)->mapWithKeys(fn ($column) => [$column => (float) ($row->{$column} ?? 0)])->all();
    }

    private function sumRows($rows): array
    {
        return collect(self::SUM_COLUMNS)->mapWithKeys(fn ($column) => [$column => (float) $rows->sum($column)])->all();
    }

    private function metrics(array $totals): array
    {
        $metrics = MetricCalculator::derived($totals + array_fill_keys(self::SUM_COLUMNS, 0));
        $metrics['cpp'] = MetricCalculator::divide((float) $metrics['spend'], (float) $metrics['purchases']);

        return $metrics;
    }

    private function accountPayload(AdAccount $account): array
    {
        return $account->only(['id', 'client_id', 'external_id', 'name', 'platform', 'currency', 'timezone', 'status']) + [
            'client' => $account->client?->only(['id', 'name']),
        ];
    }
}
