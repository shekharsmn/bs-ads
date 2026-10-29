<?php

namespace App\Services;

use App\Enums\Platform;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdGroup;
use App\Models\Campaign;
use App\Models\ConversionMapping;
use App\Models\MetricSnapshot;
use Google\Ads\GoogleAds\V23\Services\SearchGoogleAdsStreamRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PerformanceCollectionService
{
    public function collect(AdAccount $account, ?string $start = null, ?string $end = null): void
    {
        $account->loadMissing(['connection', 'client']);
        $end ??= now($account->timezone)->toDateString();
        $start ??= now($account->timezone)->subDays($account->last_collected_at ? 14 : 89)->toDateString();
        $rows = $account->platform === Platform::Google ? $this->google($account, $start, $end) : $this->meta($account, $start, $end);
        $hierarchy = $account->platform === Platform::Google ? $this->googleHierarchy($account, $start, $end) : $this->metaHierarchy($account, $start, $end);
        DB::transaction(function () use ($account, $rows, $hierarchy) {
            $now = now();
            $campaignRows = collect($rows)->map(fn ($row) => [
                'ad_account_id' => $account->id, 'external_id' => (string) $row['campaign_external_id'],
                'name' => $row['campaign_name'], 'status' => $row['campaign_status'], 'type' => $row['campaign_type'],
                'daily_budget' => $row['daily_budget'], 'created_at' => $now, 'updated_at' => $now,
            ])->keyBy('external_id')->values();
            foreach ($campaignRows->chunk(500) as $chunk) {
                Campaign::upsert($chunk->all(), ['ad_account_id', 'external_id'], ['name', 'status', 'type', 'daily_budget', 'updated_at']);
            }
            $campaignIds = Campaign::query()->where('ad_account_id', $account->id)
                ->whereIn('external_id', $campaignRows->pluck('external_id'))->pluck('id', 'external_id');

            $groupRows = collect($hierarchy['ad_groups'])->map(function ($row) use ($campaignIds, $now) {
                $campaignId = $campaignIds[(string) $row['campaign_external_id']] ?? null;
                if (! $campaignId || (string) $row['ad_group_external_id'] === '') {
                    return null;
                }

                return [
                    'campaign_id' => $campaignId,
                    'external_id' => (string) $row['ad_group_external_id'],
                    'name' => $row['ad_group_name'],
                    'status' => $row['ad_group_status'],
                    'type' => $row['ad_group_type'],
                    'daily_budget' => $row['daily_budget'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->filter()->unique(fn ($row) => $row['campaign_id'].'|'.$row['external_id'])->values();
            foreach ($groupRows->chunk(500) as $chunk) {
                AdGroup::upsert($chunk->all(), ['campaign_id', 'external_id'], ['name', 'status', 'type', 'daily_budget', 'updated_at']);
            }
            $groupIds = AdGroup::query()->whereIn('campaign_id', $campaignIds->values())
                ->whereIn('external_id', $groupRows->pluck('external_id'))->get(['id', 'campaign_id', 'external_id'])
                ->keyBy(fn ($group) => $group->campaign_id.'|'.$group->external_id);

            $adRows = collect($hierarchy['ads'])->map(function ($row) use ($campaignIds, $groupIds, $now) {
                $campaignId = $campaignIds[(string) $row['campaign_external_id']] ?? null;
                $group = $campaignId ? $groupIds->get($campaignId.'|'.(string) $row['ad_group_external_id']) : null;
                if (! $group || (string) $row['ad_external_id'] === '') {
                    return null;
                }

                return [
                    'ad_group_id' => $group->id,
                    'external_id' => (string) $row['ad_external_id'],
                    'name' => $row['ad_name'],
                    'status' => $row['ad_status'],
                    'type' => $row['ad_type'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->filter()->unique(fn ($row) => $row['ad_group_id'].'|'.$row['external_id'])->values();
            foreach ($adRows->chunk(500) as $chunk) {
                Ad::upsert($chunk->all(), ['ad_group_id', 'external_id'], ['name', 'status', 'type', 'updated_at']);
            }
            $adIds = Ad::query()->whereIn('ad_group_id', $groupIds->pluck('id'))
                ->whereIn('external_id', $adRows->pluck('external_id'))->get(['id', 'ad_group_id', 'external_id'])
                ->keyBy(fn ($ad) => $ad->ad_group_id.'|'.$ad->external_id);

            $accountByDate = [];
            $metricRows = [];
            foreach ($rows as $row) {
                $campaignId = $campaignIds[(string) $row['campaign_external_id']];
                $payload = array_merge($row['metrics'], [
                    'ad_account_id' => $account->id,
                    'campaign_id' => $campaignId,
                    'ad_group_id' => null,
                    'ad_id' => null,
                    'scope' => 'campaign',
                    'scope_key' => 'campaign:'.$campaignId,
                    'metric_date' => $row['date'],
                    'currency' => $account->currency,
                    'mapping_version' => $row['mapping_version'],
                    'collected_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $metricRows[] = $payload;
                foreach ($row['metrics'] as $key => $value) {
                    $accountByDate[$row['date']][$key] = ($accountByDate[$row['date']][$key] ?? 0) + $value;
                }
                $accountByDate[$row['date']]['mapping_version'] = max($accountByDate[$row['date']]['mapping_version'] ?? 1, $row['mapping_version']);
            }
            foreach ($accountByDate as $date => $metrics) {
                $mappingVersion = $metrics['mapping_version'];
                unset($metrics['mapping_version']);
                $metricRows[] = array_merge($metrics, [
                    'ad_account_id' => $account->id, 'campaign_id' => null, 'ad_group_id' => null, 'ad_id' => null, 'scope' => 'account',
                    'scope_key' => 'account:'.$account->id, 'metric_date' => $date, 'currency' => $account->currency,
                    'mapping_version' => $mappingVersion, 'collected_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            foreach ($hierarchy['ad_groups'] as $row) {
                $campaignId = $campaignIds[(string) $row['campaign_external_id']] ?? null;
                $group = $campaignId ? $groupIds->get($campaignId.'|'.(string) $row['ad_group_external_id']) : null;
                if (! $group) {
                    continue;
                }
                $metricRows[] = array_merge($row['metrics'], [
                    'ad_account_id' => $account->id, 'campaign_id' => $campaignId, 'ad_group_id' => $group->id, 'ad_id' => null,
                    'scope' => 'ad_group', 'scope_key' => 'ad_group:'.$group->id, 'metric_date' => $row['date'],
                    'currency' => $account->currency, 'mapping_version' => $row['mapping_version'], 'collected_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            foreach ($hierarchy['ads'] as $row) {
                $campaignId = $campaignIds[(string) $row['campaign_external_id']] ?? null;
                $group = $campaignId ? $groupIds->get($campaignId.'|'.(string) $row['ad_group_external_id']) : null;
                $ad = $group ? $adIds->get($group->id.'|'.(string) $row['ad_external_id']) : null;
                if (! $group || ! $ad) {
                    continue;
                }
                $metricRows[] = array_merge($row['metrics'], [
                    'ad_account_id' => $account->id, 'campaign_id' => $campaignId, 'ad_group_id' => $group->id, 'ad_id' => $ad->id,
                    'scope' => 'ad', 'scope_key' => 'ad:'.$ad->id, 'metric_date' => $row['date'],
                    'currency' => $account->currency, 'mapping_version' => $row['mapping_version'], 'collected_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            foreach (array_chunk($metricRows, 500) as $chunk) {
                MetricSnapshot::upsert($chunk, ['ad_account_id', 'scope_key', 'metric_date'], [
                    'campaign_id', 'ad_group_id', 'ad_id', 'scope', 'currency', 'spend', 'impressions', 'reach', 'clicks', 'conversions',
                    'leads', 'purchases', 'conversion_value', 'mapping_version', 'collected_at', 'updated_at',
                ]);
            }
            $account->update(['last_collected_at' => $now, 'last_error' => null]);
            $account->connection->update(['status' => 'healthy', 'last_synced_at' => $now, 'last_healthy_at' => $now, 'last_error' => null]);
        });
    }

    private function google(AdAccount $account, string $start, string $end): array
    {
        $credentials = $account->connection->credentials;
        $client = GoogleAdsClientFactory::fromRefreshToken($credentials['refresh_token'], $account->manager_external_id ?? $credentials['login_customer_id'] ?? $account->connection->manager_external_id);
        $query = "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign_budget.amount_micros, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date BETWEEN '$start' AND '$end' AND campaign.status != 'REMOVED'";
        $service = $client->getGoogleAdsServiceClient();
        $stream = $service->searchStream(SearchGoogleAdsStreamRequest::build($account->external_id, $query));
        $mappings = ConversionMapping::query()->where('client_id', $account->client_id)->where('platform', 'google')->where('active', true)->get()->keyBy('external_action');
        $version = $this->mappingVersion($account);
        $mapped = [];
        if ($mappings->isNotEmpty()) {
            $conversionQuery = "SELECT campaign.id, segments.date, segments.conversion_action_name, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date BETWEEN '$start' AND '$end' AND metrics.conversions > 0";
            foreach ($service->searchStream(SearchGoogleAdsStreamRequest::build($account->external_id, $conversionQuery))->readAll() as $conversionChunk) {
                foreach ($conversionChunk->getResults() as $conversionRow) {
                    $campaignSlice = json_decode($conversionRow->getCampaign()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [];
                    $metricSlice = json_decode($conversionRow->getMetrics()->serializeToJsonString(), true) ?: [];
                    $segmentSlice = json_decode($conversionRow->getSegments()->serializeToJsonString(), true) ?: [];
                    $mapping = $mappings->get($segmentSlice['conversionActionName'] ?? '');
                    if (! $mapping) {
                        continue;
                    }
                    $key = ($campaignSlice['id'] ?? '').'|'.($segmentSlice['date'] ?? '');
                    $mapped[$key] ??= ['conversions' => 0.0, 'leads' => 0.0, 'purchases' => 0.0, 'conversion_value' => 0.0];
                    $quantity = ((float) ($metricSlice['conversions'] ?? 0)) * (float) $mapping->weight;
                    $target = $mapping->canonical_metric->value;
                    if ($target !== 'conversion_value') {
                        $mapped[$key][$target] += $quantity;
                    }
                    if (in_array($target, ['conversions', 'leads', 'purchases'], true)) {
                        $mapped[$key]['conversions'] += $target === 'conversions' ? 0 : $quantity;
                    }
                    $mapped[$key]['conversion_value'] += ((float) ($metricSlice['conversionsValue'] ?? 0)) * (float) $mapping->weight;
                }
            }
        }
        $rows = [];
        foreach ($stream->readAll() as $chunk) {
            foreach ($chunk->getResults() as $row) {
                $campaign = json_decode($row->getCampaign()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [];
                $metrics = json_decode($row->getMetrics()->serializeToJsonString(), true) ?: [];
                $segments = json_decode($row->getSegments()->serializeToJsonString(), true) ?: [];
                $budget = json_decode($row->getCampaignBudget()->serializeToJsonString(), true) ?: [];
                $canonical = $mapped[(string) ($campaign['id'] ?? '').'|'.($segments['date'] ?? '')]
                    ?? $this->googleFallbackMetrics($account, $metrics);
                $rows[] = [
                    'campaign_external_id' => (string) ($campaign['id'] ?? ''),
                    'campaign_name' => (string) ($campaign['name'] ?? 'Untitled campaign'),
                    'campaign_status' => (string) ($campaign['status'] ?? 'UNKNOWN'),
                    'campaign_type' => (string) ($campaign['advertisingChannelType'] ?? null),
                    'daily_budget' => isset($budget['amountMicros']) ? ((float) $budget['amountMicros']) / 1_000_000 : null,
                    'date' => $segments['date'],
                    'mapping_version' => $version,
                    'metrics' => [
                        'spend' => ((float) ($metrics['costMicros'] ?? 0)) / 1_000_000,
                        'impressions' => (int) ($metrics['impressions'] ?? 0), 'reach' => 0,
                        'clicks' => (int) ($metrics['clicks'] ?? 0),
                        ...$canonical,
                    ],
                ];
            }
        }

        return $rows;
    }

    private function meta(AdAccount $account, string $start, string $end): array
    {
        $credentials = $account->connection->credentials;
        $url = 'https://graph.facebook.com/v24.0/'.(str_starts_with($account->external_id, 'act_') ? $account->external_id : 'act_'.$account->external_id).'/insights';
        $response = Http::timeout(60)->retry(3, 500)->get($url, [
            'access_token' => $credentials['access_token'], 'level' => 'campaign', 'time_increment' => 1,
            'time_range' => json_encode(['since' => $start, 'until' => $end]),
            'fields' => 'campaign_id,campaign_name,objective,spend,impressions,reach,clicks,actions,action_values,date_start,date_stop',
            'use_unified_attribution_setting' => true,
            'limit' => 500,
        ]);
        $response->throw();
        $data = $response->json('data', []);
        while ($next = data_get($response->json(), 'paging.next')) {
            $response = Http::timeout(60)->retry(3, 500)->get($next);
            $response->throw();
            $data = array_merge($data, $response->json('data', []));
        }
        $mappings = ConversionMapping::query()->where('client_id', $account->client_id)->where('platform', 'meta')->where('active', true)->get()->keyBy('external_action');
        $version = $this->mappingVersion($account);

        return array_map(function ($row) use ($mappings, $version) {
            $canonical = ['conversions' => 0.0, 'leads' => 0.0, 'purchases' => 0.0, 'conversion_value' => 0.0];
            foreach ($row['actions'] ?? [] as $action) {
                $mapping = $mappings->get($action['action_type'] ?? '');
                if ($mapping && $mapping->canonical_metric->value !== 'conversion_value') {
                    $quantity = ((float) ($action['value'] ?? 0)) * (float) $mapping->weight;
                    $canonical[$mapping->canonical_metric->value] += $quantity;
                    if (in_array($mapping->canonical_metric->value, ['leads', 'purchases'], true)) {
                        $canonical['conversions'] += $quantity;
                    }
                }
            }
            foreach ($row['action_values'] ?? [] as $action) {
                $mapping = $mappings->get($action['action_type'] ?? '');
                if ($mapping) {
                    $canonical['conversion_value'] += ((float) ($action['value'] ?? 0)) * (float) $mapping->weight;
                }
            }
            if ($mappings->isEmpty()) {
                $canonical = $this->metaFallbackMetrics($row);
                $canonical['conversions'] = $canonical['leads'] + $canonical['purchases'];
            }

            return [
                'campaign_external_id' => (string) $row['campaign_id'], 'campaign_name' => $row['campaign_name'] ?? 'Untitled campaign',
                'campaign_status' => 'ACTIVE', 'campaign_type' => $row['objective'] ?? null, 'daily_budget' => null,
                'date' => $row['date_start'], 'mapping_version' => $version,
                'metrics' => array_merge(['spend' => (float) ($row['spend'] ?? 0), 'impressions' => (int) ($row['impressions'] ?? 0), 'reach' => (int) ($row['reach'] ?? 0), 'clicks' => (int) ($row['clicks'] ?? 0)], $canonical),
            ];
        }, $data);
    }

    private function googleHierarchy(AdAccount $account, string $start, string $end): array
    {
        return [
            'ad_groups' => $this->googleHierarchyLevel($account, $start, $end, 'ad_group'),
            'ads' => $this->googleHierarchyLevel($account, $start, $end, 'ad'),
        ];
    }

    private function googleHierarchyLevel(AdAccount $account, string $start, string $end, string $level): array
    {
        $credentials = $account->connection->credentials;
        $client = GoogleAdsClientFactory::fromRefreshToken($credentials['refresh_token'], $account->manager_external_id ?? $credentials['login_customer_id'] ?? $account->connection->manager_external_id);
        $service = $client->getGoogleAdsServiceClient();
        $entityFields = $level === 'ad'
            ? 'ad_group_ad.ad.id, ad_group_ad.ad.name, ad_group_ad.status, ad_group_ad.ad.type, '
            : 'ad_group.status, ad_group.type, ';
        $from = $level === 'ad' ? 'ad_group_ad' : 'ad_group';
        $query = "SELECT campaign.id, ad_group.id, ad_group.name, {$entityFields}segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM {$from} WHERE segments.date BETWEEN '$start' AND '$end'";
        $mappings = ConversionMapping::query()->where('client_id', $account->client_id)->where('platform', 'google')->where('active', true)->get()->keyBy('external_action');
        $version = $this->mappingVersion($account);
        $mapped = [];
        if ($mappings->isNotEmpty()) {
            $conversionQuery = 'SELECT campaign.id, ad_group.id, '.($level === 'ad' ? 'ad_group_ad.ad.id, ' : '')."segments.date, segments.conversion_action_name, metrics.conversions, metrics.conversions_value FROM {$from} WHERE segments.date BETWEEN '$start' AND '$end' AND metrics.conversions > 0";
            foreach ($service->searchStream(SearchGoogleAdsStreamRequest::build($account->external_id, $conversionQuery))->readAll() as $chunk) {
                foreach ($chunk->getResults() as $row) {
                    $campaign = json_decode($row->getCampaign()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [];
                    $group = json_decode($row->getAdGroup()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [];
                    $ad = $level === 'ad' ? (json_decode($row->getAdGroupAd()->getAd()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: []) : [];
                    $metrics = json_decode($row->getMetrics()->serializeToJsonString(), true) ?: [];
                    $segments = json_decode($row->getSegments()->serializeToJsonString(), true) ?: [];
                    $mapping = $mappings->get($segments['conversionActionName'] ?? '');
                    if (! $mapping) {
                        continue;
                    }
                    $key = implode('|', array_filter([(string) ($campaign['id'] ?? ''), (string) ($group['id'] ?? ''), $level === 'ad' ? (string) ($ad['id'] ?? '') : null, $segments['date'] ?? ''], fn ($value) => $value !== null));
                    $mapped[$key] ??= ['conversions' => 0.0, 'leads' => 0.0, 'purchases' => 0.0, 'conversion_value' => 0.0];
                    $quantity = ((float) ($metrics['conversions'] ?? 0)) * (float) $mapping->weight;
                    $target = $mapping->canonical_metric->value;
                    if ($target !== 'conversion_value') {
                        $mapped[$key][$target] += $quantity;
                    }
                    if (in_array($target, ['leads', 'purchases'], true)) {
                        $mapped[$key]['conversions'] += $quantity;
                    }
                    $mapped[$key]['conversion_value'] += ((float) ($metrics['conversionsValue'] ?? 0)) * (float) $mapping->weight;
                }
            }
        }

        $rows = [];
        foreach ($service->searchStream(SearchGoogleAdsStreamRequest::build($account->external_id, $query))->readAll() as $chunk) {
            foreach ($chunk->getResults() as $row) {
                $campaign = json_decode($row->getCampaign()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [];
                $group = json_decode($row->getAdGroup()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [];
                $adGroupAd = $level === 'ad' ? (json_decode($row->getAdGroupAd()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: []) : [];
                $ad = $adGroupAd['ad'] ?? [];
                $metrics = json_decode($row->getMetrics()->serializeToJsonString(), true) ?: [];
                $segments = json_decode($row->getSegments()->serializeToJsonString(), true) ?: [];
                $key = implode('|', array_filter([(string) ($campaign['id'] ?? ''), (string) ($group['id'] ?? ''), $level === 'ad' ? (string) ($ad['id'] ?? '') : null, $segments['date'] ?? ''], fn ($value) => $value !== null));
                $canonical = $mapped[$key] ?? $this->googleFallbackMetrics($account, $metrics);
                $base = [
                    'campaign_external_id' => (string) ($campaign['id'] ?? ''),
                    'ad_group_external_id' => (string) ($group['id'] ?? ''),
                    'ad_group_name' => (string) ($group['name'] ?? 'Untitled group'),
                    'ad_group_status' => (string) ($group['status'] ?? 'UNKNOWN'),
                    'ad_group_type' => (string) ($group['type'] ?? null),
                    'daily_budget' => null,
                    'date' => $segments['date'],
                    'mapping_version' => $version,
                    'metrics' => [
                        'spend' => ((float) ($metrics['costMicros'] ?? 0)) / 1_000_000,
                        'impressions' => (int) ($metrics['impressions'] ?? 0), 'reach' => 0,
                        'clicks' => (int) ($metrics['clicks'] ?? 0), ...$canonical,
                    ],
                ];
                if ($level === 'ad') {
                    $base += [
                        'ad_external_id' => (string) ($ad['id'] ?? ''),
                        'ad_name' => (string) ($ad['name'] ?? ('Ad '.($ad['id'] ?? ''))),
                        'ad_status' => (string) ($adGroupAd['status'] ?? 'UNKNOWN'),
                        'ad_type' => (string) ($ad['type'] ?? null),
                    ];
                }
                $rows[] = $base;
            }
        }

        return $rows;
    }

    private function metaHierarchy(AdAccount $account, string $start, string $end): array
    {
        $adGroups = $this->metaEntityMetadata($account, 'adsets');
        $ads = $this->metaEntityMetadata($account, 'ads');

        return [
            'ad_groups' => $this->metaHierarchyLevel($account, $start, $end, 'adset', $adGroups),
            'ads' => $this->metaHierarchyLevel($account, $start, $end, 'ad', $ads),
        ];
    }

    private function metaHierarchyLevel(AdAccount $account, string $start, string $end, string $level, array $metadata): array
    {
        $credentials = $account->connection->credentials;
        $url = 'https://graph.facebook.com/v24.0/'.(str_starts_with($account->external_id, 'act_') ? $account->external_id : 'act_'.$account->external_id).'/insights';
        $fields = 'campaign_id,campaign_name,adset_id,adset_name,'.($level === 'ad' ? 'ad_id,ad_name,' : '').'objective,spend,impressions,reach,clicks,actions,action_values,date_start,date_stop';
        $response = Http::timeout(60)->retry(3, 500)->get($url, [
            'access_token' => $credentials['access_token'], 'level' => $level, 'time_increment' => 1,
            'time_range' => json_encode(['since' => $start, 'until' => $end]), 'fields' => $fields,
            'use_unified_attribution_setting' => true, 'limit' => 500,
        ]);
        $response->throw();
        $data = $response->json('data', []);
        while ($next = data_get($response->json(), 'paging.next')) {
            $response = Http::timeout(60)->retry(3, 500)->get($next);
            $response->throw();
            $data = array_merge($data, $response->json('data', []));
        }
        $mappings = ConversionMapping::query()->where('client_id', $account->client_id)->where('platform', 'meta')->where('active', true)->get()->keyBy('external_action');
        $version = $this->mappingVersion($account);

        return array_map(function (array $row) use ($level, $mappings, $metadata, $version): array {
            $canonical = ['conversions' => 0.0, 'leads' => 0.0, 'purchases' => 0.0, 'conversion_value' => 0.0];
            foreach ($row['actions'] ?? [] as $action) {
                $mapping = $mappings->get($action['action_type'] ?? '');
                if ($mapping && $mapping->canonical_metric->value !== 'conversion_value') {
                    $quantity = ((float) ($action['value'] ?? 0)) * (float) $mapping->weight;
                    $canonical[$mapping->canonical_metric->value] += $quantity;
                    if (in_array($mapping->canonical_metric->value, ['leads', 'purchases'], true)) {
                        $canonical['conversions'] += $quantity;
                    }
                }
            }
            foreach ($row['action_values'] ?? [] as $action) {
                $mapping = $mappings->get($action['action_type'] ?? '');
                if ($mapping) {
                    $canonical['conversion_value'] += ((float) ($action['value'] ?? 0)) * (float) $mapping->weight;
                }
            }
            if ($mappings->isEmpty()) {
                $canonical = $this->metaFallbackMetrics($row);
                $canonical['conversions'] = $canonical['leads'] + $canonical['purchases'];
            }
            $entityId = (string) ($level === 'ad' ? ($row['ad_id'] ?? '') : ($row['adset_id'] ?? ''));
            $entity = $metadata[$entityId] ?? [];
            $base = [
                'campaign_external_id' => (string) ($row['campaign_id'] ?? ''),
                'ad_group_external_id' => (string) ($row['adset_id'] ?? ''),
                'ad_group_name' => (string) ($row['adset_name'] ?? 'Untitled ad set'),
                'ad_group_status' => $level === 'ad' ? 'UNKNOWN' : ($entity['status'] ?? 'UNKNOWN'),
                'ad_group_type' => $level === 'ad' ? null : ($entity['optimization_goal'] ?? null), 'daily_budget' => null,
                'date' => $row['date_start'], 'mapping_version' => $version,
                'metrics' => array_merge([
                    'spend' => (float) ($row['spend'] ?? 0), 'impressions' => (int) ($row['impressions'] ?? 0),
                    'reach' => (int) ($row['reach'] ?? 0), 'clicks' => (int) ($row['clicks'] ?? 0),
                ], $canonical),
            ];
            if ($level === 'ad') {
                $base += [
                    'ad_external_id' => (string) ($row['ad_id'] ?? ''),
                    'ad_name' => (string) ($row['ad_name'] ?? 'Untitled ad'),
                    'ad_status' => $entity['status'] ?? 'UNKNOWN', 'ad_type' => data_get($entity, 'creative.object_type'),
                ];
            }

            return $base;
        }, $data);
    }

    private function metaEntityMetadata(AdAccount $account, string $edge): array
    {
        $credentials = $account->connection->credentials;
        $accountId = str_starts_with($account->external_id, 'act_') ? $account->external_id : 'act_'.$account->external_id;
        $fields = $edge === 'adsets' ? 'id,name,status,optimization_goal' : 'id,name,status,adset_id,creative{id,object_type}';
        $response = Http::timeout(60)->retry(3, 500)->get("https://graph.facebook.com/v24.0/{$accountId}/{$edge}", [
            'access_token' => $credentials['access_token'], 'fields' => $fields, 'limit' => 500,
        ]);
        $response->throw();
        $rows = $response->json('data', []);
        while ($next = data_get($response->json(), 'paging.next')) {
            $response = Http::timeout(60)->retry(3, 500)->get($next);
            $response->throw();
            $rows = array_merge($rows, $response->json('data', []));
        }

        return collect($rows)->filter(fn ($row) => filled($row['id'] ?? null))->keyBy(fn ($row) => (string) $row['id'])->all();
    }

    private function metaFallbackMetrics(array $row): array
    {
        $objective = strtoupper((string) ($row['objective'] ?? ''));
        $purchaseTypes = [
            'web_in_store_purchase',
            'web_app_in_store_purchase',
            'offsite_conversion.fb_pixel_purchase',
            'purchase',
            'omni_purchase',
            'onsite_web_purchase',
            'onsite_conversion.purchase',
            'offline_conversion.purchase',
        ];
        $leadTypes = [
            'offsite_conversion.fb_pixel_lead',
            'lead',
            'onsite_conversion.lead_grouped',
            'onsite_conversion.lead',
            'onsite_web_lead',
        ];
        $isSales = str_contains($objective, 'SALES') || str_contains($objective, 'PURCHASE');
        $isLead = str_contains($objective, 'LEAD');
        $actions = $row['actions'] ?? [];
        $actionValues = $row['action_values'] ?? [];
        $purchases = $isSales || ! $isLead ? $this->firstMetaActionValue($actions, $purchaseTypes) : 0.0;
        $leads = $isLead || ! $isSales ? $this->firstMetaActionValue($actions, $leadTypes) : 0.0;

        return [
            'conversions' => 0.0,
            'leads' => $leads,
            'purchases' => $purchases,
            'conversion_value' => $purchases > 0 ? $this->firstMetaActionValue($actionValues, $purchaseTypes) : 0.0,
        ];
    }

    private function googleFallbackMetrics(AdAccount $account, array $metrics): array
    {
        $conversions = (float) ($metrics['conversions'] ?? 0);
        $sales = in_array(strtolower((string) $account->client?->objective), ['ecommerce', 'sales', 'purchase'], true)
            || ((float) ($metrics['conversionsValue'] ?? 0) > 0 && strtolower((string) $account->client?->objective) === 'both');

        return [
            'conversions' => $conversions,
            'leads' => $sales ? 0.0 : $conversions,
            'purchases' => $sales ? $conversions : 0.0,
            'conversion_value' => (float) ($metrics['conversionsValue'] ?? 0),
        ];
    }

    private function firstMetaActionValue(array $actions, array $preferredTypes): float
    {
        $values = collect($actions)->mapWithKeys(fn ($action) => [
            (string) ($action['action_type'] ?? '') => (float) ($action['value'] ?? 0),
        ]);

        foreach ($preferredTypes as $type) {
            if ($values->has($type)) {
                return (float) $values->get($type);
            }
        }

        return 0.0;
    }

    private function mappingVersion(AdAccount $account): int
    {
        return (int) (ConversionMapping::where('client_id', $account->client_id)->where('platform', $account->platform->value)->max('version') ?? 1);
    }
}
