<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreativePerformanceService
{
    private const ACTIVE_STATUSES = ['active', 'enabled'];

    private const HISTORY_DAYS = 90;

    public function account(AdAccount $account, string $start, string $end, ?array $campaignIds = null): array
    {
        $accounts = collect([$account]);
        $scope = $campaignIds !== null ? [$account->id => $campaignIds] : null;
        $history = $this->historicalRows($accounts, $start, $scope);
        $evaluated = $this->evaluateAccount(
            $account,
            $this->rows($accounts, $start, $end, $scope),
            $history,
            false,
        );

        return $this->summary($evaluated, $start, $end, $this->peerMedians($account, $history));
    }

    public function paginateAccount(AdAccount $account, string $start, string $end, array $filters = [], ?array $campaignIds = null): array
    {
        $accounts = collect([$account]);
        $scope = $campaignIds !== null ? [$account->id => $campaignIds] : null;
        $evaluated = $this->evaluateAccount(
            $account,
            $this->rows($accounts, $start, $end, $scope),
            $this->historicalRows($accounts, $start, $scope),
            false,
        );

        return $this->paginate($evaluated, $filters);
    }

    public function client(Client $client, string $start, string $end, ?array $campaignIdsByAccount = null): array
    {
        $accountQuery = $client->adAccounts()->with('client:id,name,objective')->where('status', 'active');
        if ($campaignIdsByAccount !== null) {
            $accountQuery->whereIn('id', array_keys($campaignIdsByAccount));
        }
        $accounts = $accountQuery->get();
        $rows = $this->rows($accounts, $start, $end, $campaignIdsByAccount);
        $history = $this->historicalRows($accounts, $start, $campaignIdsByAccount);
        $evaluated = $accounts->flatMap(fn (AdAccount $account) => $this->evaluateAccount(
            $account,
            $rows->where('ad_account_id', $account->id)->values(),
            $history->where('ad_account_id', $account->id)->values(),
            false,
        ));

        return $this->summary($evaluated, $start, $end) + [
            'accounts' => $evaluated->groupBy('ad_account.id')->map(fn (Collection $ads) => [
                'account' => $ads->first()['ad_account'],
                'winner_count' => $ads->where('verdict.key', 'winner')->count(),
                'did_not_work_count' => $ads->where('verdict.key', 'did_not_work')->count(),
                'not_enough_data_count' => $ads->where('verdict.key', 'not_enough_data')->count(),
            ])->values(),
        ];
    }

    public function paginateClient(Client $client, string $start, string $end, array $filters = [], ?array $campaignIdsByAccount = null): array
    {
        $accountQuery = $client->adAccounts()->with('client:id,name,objective')->where('status', 'active');
        if ($campaignIdsByAccount !== null) {
            $accountQuery->whereIn('id', array_keys($campaignIdsByAccount));
        }
        if (! empty($filters['account_id'])) {
            $accountQuery->whereKey($filters['account_id']);
        }
        if (! empty($filters['platform'])) {
            $accountQuery->where('platform', $filters['platform']);
        }
        $accounts = $accountQuery->get();
        $rows = $this->rows($accounts, $start, $end, $campaignIdsByAccount);
        $history = $this->historicalRows($accounts, $start, $campaignIdsByAccount);
        $evaluated = $accounts->flatMap(fn (AdAccount $account) => $this->evaluateAccount(
            $account,
            $rows->where('ad_account_id', $account->id)->values(),
            $history->where('ad_account_id', $account->id)->values(),
            false,
        ));

        return $this->paginate($evaluated, $filters);
    }

    public function verdictFor(AdAccount $account, Ad $ad, string $start, string $end): array
    {
        $accounts = collect([$account]);

        return $this->evaluateAccount(
            $account,
            $this->rows($accounts, $start, $end),
            $this->historicalRows($accounts, $start),
        )->firstWhere('ad.id', $ad->id)['verdict'] ?? $this->notEnough('This ad is not effectively active or has no eligible performance context.');
    }

    private function historicalRows(Collection $accounts, string $selectedStart, ?array $campaignIdsByAccount = null): Collection
    {
        $end = CarbonImmutable::parse($selectedStart)->subDay();
        $start = $end->subDays(self::HISTORY_DAYS - 1);

        return $this->rows($accounts, $start->toDateString(), $end->toDateString(), $campaignIdsByAccount);
    }

    private function rows(Collection $accounts, string $start, string $end, ?array $campaignIdsByAccount = null): Collection
    {
        if ($accounts->isEmpty()) {
            return collect();
        }

        $query = DB::table('ads')
            ->join('ad_groups', 'ad_groups.id', '=', 'ads.ad_group_id')
            ->join('campaigns', 'campaigns.id', '=', 'ad_groups.campaign_id')
            ->join('ad_accounts', 'ad_accounts.id', '=', 'campaigns.ad_account_id')
            ->leftJoin('metric_snapshots', function (JoinClause $join) use ($start, $end) {
                $join->on('metric_snapshots.ad_id', '=', 'ads.id')
                    ->where('metric_snapshots.scope', 'ad')
                    ->whereDate('metric_snapshots.metric_date', '>=', $start)
                    ->whereDate('metric_snapshots.metric_date', '<=', $end);
            })
            ->whereIn('ad_accounts.id', $accounts->pluck('id'))
            ->whereIn(DB::raw('LOWER(ads.status)'), self::ACTIVE_STATUSES)
            ->whereIn(DB::raw('LOWER(ad_groups.status)'), self::ACTIVE_STATUSES)
            ->whereIn(DB::raw('LOWER(campaigns.status)'), self::ACTIVE_STATUSES)
            ->groupBy([
                'ads.id', 'ads.external_id', 'ads.name', 'ads.status', 'ads.type', 'ads.creative', 'ads.creative_refreshed_at',
                'campaigns.id', 'campaigns.name', 'campaigns.type', 'ad_groups.id', 'ad_groups.name', 'ad_accounts.id', 'ad_accounts.name',
                'ad_accounts.platform', 'ad_accounts.currency',
            ])
            ->select([
                'ads.id as ad_id', 'ads.external_id as ad_external_id', 'ads.name as ad_name', 'ads.status as ad_status',
                'ads.type as ad_type', 'ads.creative', 'ads.creative_refreshed_at',
                'campaigns.id as campaign_id', 'campaigns.name as campaign_name', 'campaigns.type as campaign_type',
                'ad_groups.id as ad_group_id', 'ad_groups.name as ad_group_name',
                'ad_accounts.id as ad_account_id', 'ad_accounts.name as ad_account_name',
                'ad_accounts.platform', 'ad_accounts.currency',
            ])
            ->selectRaw('COUNT(DISTINCT metric_snapshots.metric_date) as data_days')
            ->selectRaw('COALESCE(SUM(metric_snapshots.spend), 0) as spend')
            ->selectRaw('COALESCE(SUM(metric_snapshots.impressions), 0) as impressions')
            ->selectRaw('COALESCE(SUM(metric_snapshots.reach), 0) as reach')
            ->selectRaw('COALESCE(SUM(metric_snapshots.clicks), 0) as clicks')
            ->selectRaw('COALESCE(SUM(metric_snapshots.conversions), 0) as conversions')
            ->selectRaw('COALESCE(SUM(metric_snapshots.leads), 0) as leads')
            ->selectRaw('COALESCE(SUM(metric_snapshots.purchases), 0) as purchases')
            ->selectRaw('COALESCE(SUM(metric_snapshots.installs), 0) as installs')
            ->selectRaw('COALESCE(SUM(metric_snapshots.conversion_value), 0) as conversion_value');

        if ($campaignIdsByAccount !== null) {
            $query->where(function ($outer) use ($campaignIdsByAccount) {
                foreach ($campaignIdsByAccount as $accountId => $campaignIds) {
                    $outer->orWhere(function ($inner) use ($accountId, $campaignIds) {
                        $inner->where('ad_accounts.id', $accountId);
                        if ($campaignIds !== null) {
                            $inner->whereIn('campaigns.id', $campaignIds ?: [-1]);
                        }
                    });
                }
            });
        }

        return $query->get();
    }

    private function evaluateAccount(AdAccount $account, Collection $rows, Collection $historyRows, bool $includeCreative = true): Collection
    {
        return $rows->map(function ($row) use ($account, $historyRows, $includeCreative) {
            $resultKey = $this->resultKey($account->effective_reporting_objective, $row);
            $results = (float) $row->{$resultKey};
            $spend = (float) $row->spend;
            $cpr = $results > 0 ? $spend / $results : null;
            [$benchmark, $benchmarkSource] = $this->benchmark($account, $resultKey, (int) $row->ad_id, $historyRows);
            $metrics = [
                'spend' => $spend,
                'impressions' => (int) $row->impressions,
                'reach' => (int) $row->reach,
                'clicks' => (int) $row->clicks,
                'conversions' => (float) $row->conversions,
                'leads' => (float) $row->leads,
                'purchases' => (float) $row->purchases,
                'installs' => (float) $row->installs,
                'conversion_value' => (float) $row->conversion_value,
                'results' => $results,
                'result_key' => $resultKey,
                'cost_per_result' => $cpr,
                'ctr' => $row->impressions > 0 ? ((float) $row->clicks / (float) $row->impressions) * 100 : null,
                'frequency' => $row->reach > 0 ? (float) $row->impressions / (float) $row->reach : null,
                'data_days' => (int) $row->data_days,
            ];

            return [
                'ad' => [
                    'id' => $row->ad_id, 'external_id' => $row->ad_external_id, 'name' => $row->ad_name,
                    'status' => $row->ad_status, 'type' => $row->ad_type,
                    'creative' => $includeCreative && $row->creative ? json_decode($row->creative, true) : null,
                    'creative_refreshed_at' => $row->creative_refreshed_at,
                ],
                'campaign' => ['id' => $row->campaign_id, 'name' => $row->campaign_name, 'type' => $row->campaign_type],
                'ad_group' => ['id' => $row->ad_group_id, 'name' => $row->ad_group_name],
                'ad_account' => ['id' => $row->ad_account_id, 'name' => $row->ad_account_name, 'platform' => $row->platform, 'currency' => $row->currency],
                'metrics' => $metrics,
                'benchmark' => ['cost_per_result' => $benchmark, 'result_key' => $resultKey, 'source' => $benchmarkSource],
                'verdict' => $this->verdict((string) $row->platform, $metrics, $benchmark, $benchmarkSource),
            ];
        });
    }

    private function benchmark(AdAccount $account, string $resultKey, int $adId, Collection $historyRows): array
    {
        $configured = data_get($account->target_costs, $resultKey);
        if (is_numeric($configured) && (float) $configured > 0) {
            return [(float) $configured, 'configured_target'];
        }

        $values = $historyRows
            ->filter(fn ($row) => (int) $row->ad_id !== $adId && $this->resultKey($account->effective_reporting_objective, $row) === $resultKey)
            ->map(function ($row) use ($resultKey) {
                $results = (float) $row->{$resultKey};

                return $results >= 3 ? (float) $row->spend / $results : null;
            })
            ->filter(fn ($value) => $value !== null && $value > 0)
            ->sort()
            ->values();

        if ($values->isEmpty()) {
            return [null, null];
        }

        return [$this->median($values), '90_day_peer_median'];
    }

    private function verdict(string $platform, array $metrics, ?float $benchmark, ?string $benchmarkSource): array
    {
        if ($benchmark === null || $benchmark <= 0) {
            return $this->notEnough('Set an account target cost or collect enough peer history to establish a baseline.');
        }

        $isMeta = strtolower($platform) === 'meta';
        $minimumDays = $isMeta ? 3 : 7;
        $minimumResults = $isMeta ? 3 : 5;
        $minimumTraffic = $isMeta ? $metrics['impressions'] >= 1000 : $metrics['clicks'] >= 100;
        $sourceLabel = $benchmarkSource === 'configured_target' ? 'configured account target' : '90-day peer median';

        if ($metrics['results'] >= $minimumResults
            && $metrics['data_days'] >= $minimumDays
            && $minimumTraffic
            && $metrics['cost_per_result'] <= $benchmark) {
            $relative = $metrics['cost_per_result'] > 0 ? $benchmark / $metrics['cost_per_result'] : null;

            return [
                'key' => 'winner', 'label' => 'Winner', 'confidence' => $metrics['results'] >= ($minimumResults * 2) ? 'high' : 'medium',
                'reason' => "Meets the {$platform} evidence minimum and is at or below the {$sourceLabel}.",
                'relative_efficiency' => $relative,
            ];
        }

        $zeroResultFailure = $metrics['results'] <= 0
            && $metrics['data_days'] >= $minimumDays
            && $metrics['spend'] >= ($isMeta ? 2.5 : 2.0) * $benchmark
            && $minimumTraffic;
        $inefficientFailure = $metrics['results'] >= $minimumResults
            && $metrics['data_days'] >= $minimumDays
            && $minimumTraffic
            && $metrics['cost_per_result'] >= 1.5 * $benchmark;

        if ($zeroResultFailure || $inefficientFailure) {
            return [
                'key' => 'did_not_work', 'label' => 'Did not work', 'confidence' => 'high',
                'reason' => $zeroResultFailure
                    ? ($isMeta
                        ? "No results after 3+ active days, 1,000+ impressions, and 2.5× the {$sourceLabel} in spend."
                        : "No results after 7+ active days, 100+ clicks, and 2× the {$sourceLabel} in spend.")
                    : "Enough results were recorded, but cost per result is at least 50% above the {$sourceLabel}.",
                'relative_efficiency' => $metrics['cost_per_result'] ? $benchmark / $metrics['cost_per_result'] : 0,
            ];
        }

        return $this->notEnough('The ad has not crossed the platform-specific evidence threshold.');
    }

    private function summary(Collection $ads, string $start, string $end, array $fallbackTargets = []): array
    {
        $sorted = $ads->sortByDesc(fn (array $row) => $row['verdict']['relative_efficiency'] ?? -1)->values();
        $winners = $this->hydrateCreatives($sorted->where('verdict.key', 'winner')->take(12)->values());
        $failed = $this->hydrateCreatives($sorted->where('verdict.key', 'did_not_work')->sortByDesc('metrics.spend')->take(12)->values());

        return [
            'reporting_dates' => ['start' => $start, 'end' => $end],
            'fallback_targets' => $fallbackTargets,
            'methodology' => [
                'active_ads_only' => true,
                'winner' => 'Uses the configured target cost first, otherwise a leave-one-out 90-day peer median; Meta requires 3+ results/3+ days/1,000+ impressions and Google requires 5+ results/7+ days/100+ clicks.',
                'meta_failure' => '3+ active days, 1,000+ impressions, and 2.5× benchmark spend with no result; or enough results at 1.5× benchmark cost.',
                'google_failure' => '7+ active days, 100+ clicks, and 2× benchmark spend with no result; or enough results at 1.5× benchmark cost.',
            ],
            'counts' => [
                'active_ads' => $ads->count(),
                'winners' => $ads->where('verdict.key', 'winner')->count(),
                'did_not_work' => $ads->where('verdict.key', 'did_not_work')->count(),
                'not_enough_data' => $ads->where('verdict.key', 'not_enough_data')->count(),
            ],
            'winners' => $winners,
            'did_not_work' => $failed,
            'not_enough_data' => [],
        ];
    }

    private function peerMedians(AdAccount $account, Collection $historyRows): array
    {
        return collect(['leads', 'purchases', 'installs'])->mapWithKeys(function (string $resultKey) use ($account, $historyRows): array {
            $values = $historyRows
                ->filter(fn ($row) => $this->resultKey($account->effective_reporting_objective, $row) === $resultKey)
                ->map(function ($row) use ($resultKey): ?float {
                    $results = (float) $row->{$resultKey};

                    return $results >= 3 && (float) $row->spend > 0 ? (float) $row->spend / $results : null;
                })
                ->filter(fn ($value) => $value !== null && $value > 0)
                ->sort()
                ->values();

            return [$resultKey => $values->isEmpty() ? null : $this->median($values)];
        })->all();
    }

    private function median(Collection $values): float
    {
        $middle = intdiv($values->count(), 2);

        return $values->count() % 2 === 0
            ? ((float) $values[$middle - 1] + (float) $values[$middle]) / 2
            : (float) $values[$middle];
    }

    private function paginate(Collection $ads, array $filters): array
    {
        $verdict = $filters['verdict'] ?? 'winner';
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 24)));
        $filtered = $ads->where('verdict.key', $verdict);
        if ($search !== '') {
            $filtered = $filtered->filter(fn (array $row) => str_contains(strtolower(implode(' ', [
                $row['ad']['name'], $row['campaign']['name'], $row['ad_group']['name'], $row['ad_account']['name'],
            ])), $search));
        }
        $sorted = $verdict === 'winner'
            ? $filtered->sortByDesc(fn (array $row) => $row['verdict']['relative_efficiency'] ?? -1)
            : $filtered->sortByDesc(fn (array $row) => $row['metrics']['spend']);
        $sorted = $sorted->values();
        $total = $sorted->count();
        $pageRows = $this->hydrateCreatives($sorted->slice(($page - 1) * $perPage, $perPage)->values());

        return [
            'data' => $pageRows,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    private function hydrateCreatives(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }
        $creatives = Ad::query()->whereIn('id', $rows->pluck('ad.id'))->pluck('creative', 'id');

        return $rows->map(function (array $row) use ($creatives) {
            $row['ad']['creative'] = $creatives->get($row['ad']['id']);

            return $row;
        })->values();
    }

    private function resultKey(string $objective, object $row): string
    {
        $type = strtolower((string) $row->campaign_type);
        if (str_contains($type, 'lead')) {
            return 'leads';
        }
        if (str_contains($type, 'sale') || str_contains($type, 'purchase') || str_contains($type, 'shop')) {
            return 'purchases';
        }
        if (str_contains($type, 'app') || str_contains($type, 'install')) {
            return 'installs';
        }

        if ($objective === 'both') {
            return collect([
                'leads' => (float) $row->leads,
                'purchases' => (float) $row->purchases,
                'installs' => (float) $row->installs,
            ])->sortDesc()->keys()->first();
        }

        return match ($objective) {
            'ecommerce' => 'purchases',
            'app_installs' => 'installs',
            default => 'leads',
        };
    }

    private function notEnough(string $reason): array
    {
        return ['key' => 'not_enough_data', 'label' => 'Not enough data', 'confidence' => 'low', 'reason' => $reason, 'relative_efficiency' => null];
    }
}
