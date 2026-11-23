<?php

namespace App\Services;

use App\Enums\Platform;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdGroup;
use App\Models\Campaign;
use App\Models\ConversionMapping;
use App\Models\MetricSnapshot;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V23\Services\SearchGoogleAdsStreamRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PerformanceCollectionService
{
    private const INITIAL_COLLECTION_DAYS = 30;

    private const INCREMENTAL_COLLECTION_DAYS = 14;

    /** Rich Meta creative expansions can exceed Graph's response-size limit at the normal page size. */
    private const META_CREATIVE_PAGE_SIZE = 50;

    public function collect(AdAccount $account, ?string $start = null, ?string $end = null): void
    {
        $account->loadMissing(['connection', 'client']);
        $end ??= now($account->timezone)->subDay()->toDateString();
        $windowDays = $account->last_collected_at ? self::INCREMENTAL_COLLECTION_DAYS : self::INITIAL_COLLECTION_DAYS;
        $start ??= CarbonImmutable::parse($end, $account->timezone)->subDays($windowDays - 1)->toDateString();
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
                    'creative' => filled($row['creative'] ?? null) ? json_encode($row['creative'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                    'creative_refreshed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->filter()->unique(fn ($row) => $row['ad_group_id'].'|'.$row['external_id'])->values();
            foreach ($adRows->chunk(500) as $chunk) {
                Ad::upsert($chunk->all(), ['ad_group_id', 'external_id'], ['name', 'status', 'type', 'creative', 'creative_refreshed_at', 'updated_at']);
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
                    'leads', 'purchases', 'installs', 'conversion_value', 'mapping_version', 'collected_at', 'updated_at',
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
        $query = "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign_budget.amount_micros, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value, metrics.biddable_app_install_conversions FROM campaign WHERE segments.date BETWEEN '$start' AND '$end' AND campaign.status != 'REMOVED'";
        $service = $client->getGoogleAdsServiceClient();
        $stream = $service->searchStream(SearchGoogleAdsStreamRequest::build($account->external_id, $query), ['timeoutMillis' => 45000]);
        $mappings = ConversionMapping::query()->where('client_id', $account->client_id)->where('platform', 'google')->where('active', true)->get()->keyBy('external_action');
        $version = $this->mappingVersion($account);
        $mapped = [];
        if ($mappings->isNotEmpty()) {
            $conversionQuery = "SELECT campaign.id, segments.date, segments.conversion_action_name, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date BETWEEN '$start' AND '$end' AND metrics.conversions > 0";
            foreach ($service->searchStream(SearchGoogleAdsStreamRequest::build($account->external_id, $conversionQuery), ['timeoutMillis' => 45000])->readAll() as $conversionChunk) {
                foreach ($conversionChunk->getResults() as $conversionRow) {
                    $campaignSlice = json_decode($conversionRow->getCampaign()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [];
                    $metricSlice = json_decode($conversionRow->getMetrics()->serializeToJsonString(), true) ?: [];
                    $segmentSlice = json_decode($conversionRow->getSegments()->serializeToJsonString(), true) ?: [];
                    $mapping = $mappings->get($segmentSlice['conversionActionName'] ?? '');
                    if (! $mapping) {
                        continue;
                    }
                    $key = ($campaignSlice['id'] ?? '').'|'.($segmentSlice['date'] ?? '');
                    $mapped[$key] ??= $this->emptyCanonicalMetrics();
                    $quantity = ((float) ($metricSlice['conversions'] ?? 0)) * (float) $mapping->weight;
                    $target = $mapping->canonical_metric->value;
                    if ($target !== 'conversion_value') {
                        $mapped[$key][$target] += $quantity;
                    }
                    if (in_array($target, ['conversions', 'leads', 'purchases', 'installs'], true)) {
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
                $canonical = $this->googleCanonicalMetrics($account, $metrics, $mapped[(string) ($campaign['id'] ?? '').'|'.($segments['date'] ?? '')] ?? null);
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
        $response = Http::connectTimeout(10)->timeout(45)->get($url, [
            'access_token' => $credentials['access_token'], 'level' => 'campaign', 'time_increment' => 1,
            'time_range' => json_encode(['since' => $start, 'until' => $end]),
            'fields' => 'campaign_id,campaign_name,objective,spend,impressions,reach,clicks,actions,action_values,date_start,date_stop',
            'use_unified_attribution_setting' => true,
            'limit' => 500,
        ]);
        $response->throw();
        $data = $response->json('data', []);
        while ($next = data_get($response->json(), 'paging.next')) {
            $response = Http::connectTimeout(10)->timeout(45)->get($next);
            $response->throw();
            $data = array_merge($data, $response->json('data', []));
        }
        $mappings = ConversionMapping::query()->where('client_id', $account->client_id)->where('platform', 'meta')->where('active', true)->get()->keyBy('external_action');
        $version = $this->mappingVersion($account);

        return array_map(function ($row) use ($mappings, $version) {
            $canonical = $this->emptyCanonicalMetrics();
            foreach ($row['actions'] ?? [] as $action) {
                $mapping = $mappings->get($action['action_type'] ?? '');
                if ($mapping && $mapping->canonical_metric->value !== 'conversion_value') {
                    $quantity = ((float) ($action['value'] ?? 0)) * (float) $mapping->weight;
                    $canonical[$mapping->canonical_metric->value] += $quantity;
                    if (in_array($mapping->canonical_metric->value, ['leads', 'purchases', 'installs'], true)) {
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
            $fallback = $this->metaFallbackMetrics($row);
            if ($mappings->isEmpty()) {
                $canonical = $fallback;
            } elseif ($canonical['installs'] <= 0 && $fallback['installs'] > 0) {
                $canonical['installs'] = $fallback['installs'];
                $canonical['conversions'] += $fallback['installs'];
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
        $assetGroups = $this->googleAssetGroups($account, $start, $end);

        return [
            'ad_groups' => array_merge($this->googleHierarchyLevel($account, $start, $end, 'ad_group'), $assetGroups['ad_groups']),
            'ads' => array_merge($this->googleHierarchyLevel($account, $start, $end, 'ad'), $assetGroups['ads']),
        ];
    }

    private function googleAssetGroups(AdAccount $account, string $start, string $end): array
    {
        $credentials = $account->connection->credentials;
        $client = GoogleAdsClientFactory::fromRefreshToken($credentials['refresh_token'], $account->manager_external_id ?? $credentials['login_customer_id'] ?? $account->connection->manager_external_id);
        $service = $client->getGoogleAdsServiceClient();
        $assetQuery = "SELECT campaign.id, asset_group.id, asset_group.name, asset_group.status, asset_group_asset.asset, asset_group_asset.field_type, asset.resource_name, asset.name, asset.type, asset.text_asset.text, asset.image_asset.full_size.url, asset.image_asset.mime_type, asset.youtube_video_asset.youtube_video_id, asset.youtube_video_asset.youtube_video_title FROM asset_group_asset WHERE campaign.advertising_channel_type = 'PERFORMANCE_MAX' AND asset_group_asset.status != 'REMOVED'";
        $creativeByGroup = [];
        foreach ($service->searchStream(SearchGoogleAdsStreamRequest::build($account->external_id, $assetQuery), ['timeoutMillis' => 45000])->readAll() as $chunk) {
            foreach ($chunk->getResults() as $row) {
                $group = json_decode($row->getAssetGroup()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [];
                $link = json_decode($row->getAssetGroupAsset()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [];
                $asset = json_decode($row->getAsset()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [];
                $groupId = (string) ($group['id'] ?? '');
                if ($groupId === '') {
                    continue;
                }
                $creativeByGroup[$groupId] ??= [
                    'provider' => 'google', 'entity_kind' => 'asset_group', 'format' => 'PERFORMANCE_MAX_ASSET_GROUP',
                    'headlines' => [], 'descriptions' => [], 'primary_texts' => [], 'destination_urls' => [], 'media' => [],
                ];
                $fieldType = strtoupper((string) ($link['fieldType'] ?? ''));
                $text = data_get($asset, 'textAsset.text');
                if ($text) {
                    $target = str_contains($fieldType, 'DESCRIPTION') ? 'descriptions' : (str_contains($fieldType, 'BUSINESS_NAME') ? 'primary_texts' : 'headlines');
                    $creativeByGroup[$groupId][$target][] = $text;
                }
                $resource = $asset['resourceName'] ?? ($link['asset'] ?? null);
                if ($image = data_get($asset, 'imageAsset.fullSize.url')) {
                    $creativeByGroup[$groupId]['media'][] = [
                        'type' => 'image', 'url' => $image, 'preview_url' => $image, 'source_url' => $image,
                        'asset_id' => $resource, 'provider' => 'google', 'downloadable' => true,
                    ];
                }
                if ($youtubeId = data_get($asset, 'youtubeVideoAsset.youtubeVideoId')) {
                    $creativeByGroup[$groupId]['media'][] = [
                        'type' => 'youtube_video', 'url' => "https://i.ytimg.com/vi/{$youtubeId}/hqdefault.jpg",
                        'preview_url' => "https://i.ytimg.com/vi/{$youtubeId}/hqdefault.jpg",
                        'embed_url' => "https://www.youtube.com/embed/{$youtubeId}",
                        'provider_url' => "https://www.youtube.com/watch?v={$youtubeId}",
                        'asset_id' => $resource, 'provider' => 'youtube', 'downloadable' => false, 'thumbnail_downloadable' => true,
                    ];
                }
            }
        }
        foreach ($creativeByGroup as &$creative) {
            foreach (['headlines', 'descriptions', 'primary_texts'] as $field) {
                $creative[$field] = array_values(array_unique($creative[$field]));
            }
            $creative['media'] = collect($creative['media'])->unique(fn (array $item) => $item['asset_id'] ?? $item['url'])->values()->all();
        }
        unset($creative);

        $query = "SELECT campaign.id, asset_group.id, asset_group.name, asset_group.status, asset_group.final_urls, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value, metrics.biddable_app_install_conversions FROM asset_group WHERE campaign.advertising_channel_type = 'PERFORMANCE_MAX' AND segments.date BETWEEN '$start' AND '$end'";
        $groups = [];
        $ads = [];
        $version = $this->mappingVersion($account);
        foreach ($service->searchStream(SearchGoogleAdsStreamRequest::build($account->external_id, $query), ['timeoutMillis' => 45000])->readAll() as $chunk) {
            foreach ($chunk->getResults() as $row) {
                $campaign = json_decode($row->getCampaign()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [];
                $group = json_decode($row->getAssetGroup()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [];
                $metrics = json_decode($row->getMetrics()->serializeToJsonString(), true) ?: [];
                $segments = json_decode($row->getSegments()->serializeToJsonString(), true) ?: [];
                $groupId = (string) ($group['id'] ?? '');
                if ($groupId === '') {
                    continue;
                }
                $canonical = $this->googleCanonicalMetrics($account, $metrics, null);
                $metricPayload = [
                    'spend' => ((float) ($metrics['costMicros'] ?? 0)) / 1_000_000,
                    'impressions' => (int) ($metrics['impressions'] ?? 0), 'reach' => 0,
                    'clicks' => (int) ($metrics['clicks'] ?? 0), ...$canonical,
                ];
                $base = [
                    'campaign_external_id' => (string) ($campaign['id'] ?? ''),
                    'ad_group_external_id' => 'pmax-asset-group:'.$groupId,
                    'ad_group_name' => (string) ($group['name'] ?? ('Asset group '.$groupId)),
                    'ad_group_status' => (string) ($group['status'] ?? 'UNKNOWN'),
                    'ad_group_type' => 'PERFORMANCE_MAX_ASSET_GROUP', 'daily_budget' => null,
                    'date' => $segments['date'], 'mapping_version' => $version, 'metrics' => $metricPayload,
                ];
                $groups[] = $base;
                $creative = $creativeByGroup[$groupId] ?? [
                    'provider' => 'google', 'entity_kind' => 'asset_group', 'format' => 'PERFORMANCE_MAX_ASSET_GROUP', 'media' => [],
                ];
                $creative['destination_urls'] = array_values(array_unique($group['finalUrls'] ?? []));
                $ads[] = $base + [
                    'ad_external_id' => 'pmax-asset-group-creative:'.$groupId,
                    'ad_name' => (string) ($group['name'] ?? ('Asset group '.$groupId)),
                    'ad_status' => (string) ($group['status'] ?? 'UNKNOWN'),
                    'ad_type' => 'PERFORMANCE_MAX_ASSET_GROUP', 'creative' => $creative,
                ];
            }
        }

        return ['ad_groups' => $groups, 'ads' => $ads];
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
        $creativeFields = $level === 'ad'
            ? implode(', ', [
                'ad_group_ad.ad.final_urls', 'ad_group_ad.ad.responsive_search_ad.headlines', 'ad_group_ad.ad.responsive_search_ad.descriptions',
                'ad_group_ad.ad.responsive_search_ad.path1', 'ad_group_ad.ad.responsive_search_ad.path2', 'ad_group_ad.ad.image_ad.preview_image_url',
                'ad_group_ad.ad.responsive_display_ad.marketing_images', 'ad_group_ad.ad.responsive_display_ad.square_marketing_images',
                'ad_group_ad.ad.responsive_display_ad.logo_images', 'ad_group_ad.ad.responsive_display_ad.square_logo_images',
                'ad_group_ad.ad.responsive_display_ad.headlines', 'ad_group_ad.ad.responsive_display_ad.long_headline',
                'ad_group_ad.ad.responsive_display_ad.descriptions', 'ad_group_ad.ad.responsive_display_ad.youtube_videos',
                'ad_group_ad.ad.responsive_display_ad.business_name', 'ad_group_ad.ad.responsive_display_ad.call_to_action_text',
                'ad_group_ad.ad.legacy_responsive_display_ad.short_headline', 'ad_group_ad.ad.legacy_responsive_display_ad.long_headline',
                'ad_group_ad.ad.legacy_responsive_display_ad.description', 'ad_group_ad.ad.legacy_responsive_display_ad.business_name',
                'ad_group_ad.ad.legacy_responsive_display_ad.marketing_image', 'ad_group_ad.ad.legacy_responsive_display_ad.square_marketing_image',
                'ad_group_ad.ad.legacy_responsive_display_ad.logo_image', 'ad_group_ad.ad.legacy_responsive_display_ad.square_logo_image',
                'ad_group_ad.ad.legacy_responsive_display_ad.call_to_action_text',
                'ad_group_ad.ad.app_ad.mandatory_ad_text', 'ad_group_ad.ad.app_ad.headlines', 'ad_group_ad.ad.app_ad.descriptions',
                'ad_group_ad.ad.app_ad.images', 'ad_group_ad.ad.app_ad.youtube_videos', 'ad_group_ad.ad.video_ad.video',
                'ad_group_ad.ad.demand_gen_multi_asset_ad.marketing_images', 'ad_group_ad.ad.demand_gen_multi_asset_ad.square_marketing_images',
                'ad_group_ad.ad.demand_gen_multi_asset_ad.portrait_marketing_images', 'ad_group_ad.ad.demand_gen_multi_asset_ad.logo_images',
                'ad_group_ad.ad.demand_gen_multi_asset_ad.headlines', 'ad_group_ad.ad.demand_gen_multi_asset_ad.descriptions',
                'ad_group_ad.ad.demand_gen_multi_asset_ad.business_name', 'ad_group_ad.ad.demand_gen_multi_asset_ad.call_to_action_text',
                'ad_group_ad.ad.demand_gen_carousel_ad.logo_image', 'ad_group_ad.ad.demand_gen_carousel_ad.headline',
                'ad_group_ad.ad.demand_gen_carousel_ad.description', 'ad_group_ad.ad.demand_gen_carousel_ad.carousel_cards',
                'ad_group_ad.ad.demand_gen_carousel_ad.call_to_action_text',
                'ad_group_ad.ad.demand_gen_video_responsive_ad.headlines', 'ad_group_ad.ad.demand_gen_video_responsive_ad.long_headlines',
                'ad_group_ad.ad.demand_gen_video_responsive_ad.descriptions', 'ad_group_ad.ad.demand_gen_video_responsive_ad.videos',
                'ad_group_ad.ad.demand_gen_video_responsive_ad.logo_images', 'ad_group_ad.ad.demand_gen_video_responsive_ad.companion_banners',
                'ad_group_ad.ad.demand_gen_video_responsive_ad.call_to_actions',
                'ad_group_ad.ad.demand_gen_product_ad.headline', 'ad_group_ad.ad.demand_gen_product_ad.description',
                'ad_group_ad.ad.demand_gen_product_ad.logo_image', 'ad_group_ad.ad.demand_gen_product_ad.call_to_action',
            ]).', '
            : '';
        $query = "SELECT campaign.id, ad_group.id, ad_group.name, {$entityFields}{$creativeFields}segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value, metrics.biddable_app_install_conversions FROM {$from} WHERE segments.date BETWEEN '$start' AND '$end'";
        $mappings = ConversionMapping::query()->where('client_id', $account->client_id)->where('platform', 'google')->where('active', true)->get()->keyBy('external_action');
        $version = $this->mappingVersion($account);
        $mapped = [];
        if ($mappings->isNotEmpty()) {
            $conversionQuery = 'SELECT campaign.id, ad_group.id, '.($level === 'ad' ? 'ad_group_ad.ad.id, ' : '')."segments.date, segments.conversion_action_name, metrics.conversions, metrics.conversions_value FROM {$from} WHERE segments.date BETWEEN '$start' AND '$end' AND metrics.conversions > 0";
            foreach ($service->searchStream(SearchGoogleAdsStreamRequest::build($account->external_id, $conversionQuery), ['timeoutMillis' => 45000])->readAll() as $chunk) {
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
                    $mapped[$key] ??= $this->emptyCanonicalMetrics();
                    $quantity = ((float) ($metrics['conversions'] ?? 0)) * (float) $mapping->weight;
                    $target = $mapping->canonical_metric->value;
                    if ($target !== 'conversion_value') {
                        $mapped[$key][$target] += $quantity;
                    }
                    if (in_array($target, ['leads', 'purchases', 'installs'], true)) {
                        $mapped[$key]['conversions'] += $quantity;
                    }
                    $mapped[$key]['conversion_value'] += ((float) ($metrics['conversionsValue'] ?? 0)) * (float) $mapping->weight;
                }
            }
        }

        $rawRows = [];
        foreach ($service->searchStream(SearchGoogleAdsStreamRequest::build($account->external_id, $query), ['timeoutMillis' => 45000])->readAll() as $chunk) {
            foreach ($chunk->getResults() as $row) {
                $rawRows[] = [
                    'campaign' => json_decode($row->getCampaign()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [],
                    'group' => json_decode($row->getAdGroup()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [],
                    'ad_group_ad' => $level === 'ad' ? (json_decode($row->getAdGroupAd()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: []) : [],
                    'metrics' => json_decode($row->getMetrics()->serializeToJsonString(), true) ?: [],
                    'segments' => json_decode($row->getSegments()->serializeToJsonString(), true) ?: [],
                ];
            }
        }
        $assetMap = $level === 'ad'
            ? $this->googleAssetMap($service, $account, collect($rawRows)->pluck('ad_group_ad.ad')->filter()->all())
            : [];
        $rows = [];
        foreach ($rawRows as $raw) {
                $campaign = $raw['campaign'];
                $group = $raw['group'];
                $adGroupAd = $raw['ad_group_ad'];
                $ad = $adGroupAd['ad'] ?? [];
                $metrics = $raw['metrics'];
                $segments = $raw['segments'];
                $key = implode('|', array_filter([(string) ($campaign['id'] ?? ''), (string) ($group['id'] ?? ''), $level === 'ad' ? (string) ($ad['id'] ?? '') : null, $segments['date'] ?? ''], fn ($value) => $value !== null));
                $canonical = $this->googleCanonicalMetrics($account, $metrics, $mapped[$key] ?? null);
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
                        'creative' => $this->googleCreative($ad, $assetMap),
                    ];
                }
                $rows[] = $base;
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
        $response = Http::connectTimeout(10)->timeout(45)->get($url, [
            'access_token' => $credentials['access_token'], 'level' => $level, 'time_increment' => 1,
            'time_range' => json_encode(['since' => $start, 'until' => $end]), 'fields' => $fields,
            'use_unified_attribution_setting' => true, 'limit' => 500,
        ]);
        $response->throw();
        $data = $response->json('data', []);
        while ($next = data_get($response->json(), 'paging.next')) {
            $response = Http::connectTimeout(10)->timeout(45)->get($next);
            $response->throw();
            $data = array_merge($data, $response->json('data', []));
        }
        $mappings = ConversionMapping::query()->where('client_id', $account->client_id)->where('platform', 'meta')->where('active', true)->get()->keyBy('external_action');
        $version = $this->mappingVersion($account);

        return array_map(function (array $row) use ($level, $mappings, $metadata, $version): array {
            $canonical = $this->emptyCanonicalMetrics();
            foreach ($row['actions'] ?? [] as $action) {
                $mapping = $mappings->get($action['action_type'] ?? '');
                if ($mapping && $mapping->canonical_metric->value !== 'conversion_value') {
                    $quantity = ((float) ($action['value'] ?? 0)) * (float) $mapping->weight;
                    $canonical[$mapping->canonical_metric->value] += $quantity;
                    if (in_array($mapping->canonical_metric->value, ['leads', 'purchases', 'installs'], true)) {
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
            $fallback = $this->metaFallbackMetrics($row);
            if ($mappings->isEmpty()) {
                $canonical = $fallback;
            } elseif ($canonical['installs'] <= 0 && $fallback['installs'] > 0) {
                $canonical['installs'] = $fallback['installs'];
                $canonical['conversions'] += $fallback['installs'];
            }
            $entityId = (string) ($level === 'ad' ? ($row['ad_id'] ?? '') : ($row['adset_id'] ?? ''));
            $entity = $metadata[$entityId] ?? [];
            $base = [
                'campaign_external_id' => (string) ($row['campaign_id'] ?? ''),
                'ad_group_external_id' => (string) ($row['adset_id'] ?? ''),
                'ad_group_name' => (string) ($row['adset_name'] ?? 'Untitled ad set'),
                'ad_group_status' => $level === 'ad' ? 'UNKNOWN' : ($entity['effective_status'] ?? $entity['status'] ?? 'UNKNOWN'),
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
                    'ad_status' => $entity['effective_status'] ?? $entity['status'] ?? 'UNKNOWN', 'ad_type' => data_get($entity, 'creative.object_type'),
                    'creative' => $this->metaCreative($entity['creative'] ?? []),
                ];
            }

            return $base;
        }, $data);
    }

    private function metaEntityMetadata(AdAccount $account, string $edge): array
    {
        $credentials = $account->connection->credentials;
        $accountId = str_starts_with($account->external_id, 'act_') ? $account->external_id : 'act_'.$account->external_id;
        $fields = $edge === 'adsets'
            ? 'id,name,status,effective_status,optimization_goal'
            : 'id,name,status,effective_status,adset_id,creative{id,name,object_type,thumbnail_url,image_url,instagram_permalink_url,effective_object_story_id,object_story_spec,asset_feed_spec,url_tags}';
        $response = Http::connectTimeout(10)->timeout(45)->get("https://graph.facebook.com/v24.0/{$accountId}/{$edge}", [
            'access_token' => $credentials['access_token'], 'fields' => $fields,
            'limit' => $edge === 'ads' ? self::META_CREATIVE_PAGE_SIZE : 500,
        ]);
        $response->throw();
        $rows = $response->json('data', []);
        while ($next = data_get($response->json(), 'paging.next')) {
            $response = Http::connectTimeout(10)->timeout(45)->get($next);
            $response->throw();
            $rows = array_merge($rows, $response->json('data', []));
        }

        if ($edge === 'ads') {
            $videoIds = collect($rows)->flatMap(fn (array $row) => $this->metaVideoIds($row['creative'] ?? []))->unique()->values();
            $sources = [];
            foreach ($videoIds->chunk(50) as $chunk) {
                $videoResponse = Http::connectTimeout(10)->timeout(45)->get('https://graph.facebook.com/v24.0/', [
                    'access_token' => $credentials['access_token'], 'ids' => $chunk->implode(','),
                    'fields' => 'id,source,picture,permalink_url',
                ]);
                if ($videoResponse->successful()) {
                    $sources += $videoResponse->json() ?: [];
                }
            }
            foreach ($rows as &$row) {
                $row['creative']['_video_sources'] = $sources;
            }
            unset($row);
        }

        return collect($rows)->filter(fn ($row) => filled($row['id'] ?? null))->keyBy(fn ($row) => (string) $row['id'])->all();
    }

    private function googleCreative(array $ad, array $assets = []): array
    {
        $responsive = $ad['responsiveSearchAd'] ?? [];
        $headlines = collect($responsive['headlines'] ?? [])->pluck('text')->filter();
        $descriptions = collect($responsive['descriptions'] ?? [])->pluck('text')->filter();
        $preview = data_get($ad, 'imageAd.previewImageUrl');
        $cta = data_get($ad, 'responsiveDisplayAd.callToActionText')
            ?? data_get($ad, 'legacyResponsiveDisplayAd.callToActionText')
            ?? data_get($ad, 'demandGenMultiAssetAd.callToActionText')
            ?? data_get($ad, 'demandGenCarouselAd.callToActionText');
        $media = collect();
        if ($preview) {
            $media->push(['type' => 'image', 'url' => $preview, 'preview_url' => $preview, 'source_url' => $preview, 'provider' => 'google', 'downloadable' => true]);
        }
        foreach ($this->googleAssetReferences($ad) as $reference) {
            $asset = $assets[$reference] ?? null;
            if (! $asset) {
                continue;
            }
            if ($asset['text'] ?? null) {
                if (str_contains(strtolower($reference.' '.($asset['field_path'] ?? '')), 'description')) {
                    $descriptions->push($asset['text']);
                } else {
                    $headlines->push($asset['text']);
                }
            }
            $cta ??= $asset['cta'] ?? null;
            if ($asset['image_url'] ?? null) {
                $media->push([
                    'type' => 'image', 'url' => $asset['image_url'], 'preview_url' => $asset['image_url'],
                    'source_url' => $asset['image_url'], 'asset_id' => $reference, 'provider' => 'google', 'downloadable' => true,
                ]);
            }
            if ($asset['youtube_id'] ?? null) {
                $youtubeId = $asset['youtube_id'];
                $media->push([
                    'type' => 'youtube_video', 'url' => "https://i.ytimg.com/vi/{$youtubeId}/hqdefault.jpg",
                    'preview_url' => "https://i.ytimg.com/vi/{$youtubeId}/hqdefault.jpg",
                    'embed_url' => "https://www.youtube.com/embed/{$youtubeId}",
                    'provider_url' => "https://www.youtube.com/watch?v={$youtubeId}",
                    'asset_id' => $reference, 'provider' => 'youtube', 'downloadable' => false, 'thumbnail_downloadable' => true,
                ]);
            }
        }
        $embeddedTexts = $this->googleEmbeddedTexts($ad);
        $headlines = $headlines->merge($embeddedTexts['headlines']);
        $descriptions = $descriptions->merge($embeddedTexts['descriptions']);

        return array_filter([
            'provider' => 'google',
            'entity_kind' => 'ad',
            'format' => $ad['type'] ?? null,
            'headlines' => $headlines->filter()->unique()->values()->all(),
            'descriptions' => $descriptions->filter()->unique()->values()->all(),
            'display_path' => implode('/', array_filter([$responsive['path1'] ?? null, $responsive['path2'] ?? null])),
            'cta' => $cta,
            'destination_urls' => $ad['finalUrls'] ?? [],
            'media' => $media->unique(fn (array $item) => ($item['asset_id'] ?? '').'|'.($item['source_url'] ?? $item['embed_url'] ?? $item['url'] ?? ''))->values()->all(),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function googleAssetMap($service, AdAccount $account, array $ads): array
    {
        $references = collect($ads)->flatMap(fn (array $ad) => $this->googleAssetReferences($ad))->unique()->values();
        $assets = [];
        foreach ($references->chunk(100) as $chunk) {
            $quoted = $chunk->map(fn (string $resource) => "'".str_replace("'", "\\'", $resource)."'")->implode(',');
            $query = "SELECT asset.resource_name, asset.name, asset.type, asset.text_asset.text, asset.image_asset.full_size.url, asset.image_asset.mime_type, asset.youtube_video_asset.youtube_video_id, asset.youtube_video_asset.youtube_video_title, asset.call_to_action_asset.call_to_action FROM asset WHERE asset.resource_name IN ({$quoted})";
            foreach ($service->searchStream(SearchGoogleAdsStreamRequest::build($account->external_id, $query), ['timeoutMillis' => 45000])->readAll() as $resultChunk) {
                foreach ($resultChunk->getResults() as $row) {
                    $asset = json_decode($row->getAsset()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [];
                    $resource = (string) ($asset['resourceName'] ?? '');
                    if ($resource !== '') {
                        $assets[$resource] = [
                            'name' => $asset['name'] ?? null, 'type' => $asset['type'] ?? null,
                            'text' => data_get($asset, 'textAsset.text'),
                            'image_url' => data_get($asset, 'imageAsset.fullSize.url'),
                            'mime_type' => data_get($asset, 'imageAsset.mimeType'),
                            'youtube_id' => data_get($asset, 'youtubeVideoAsset.youtubeVideoId'),
                            'youtube_title' => data_get($asset, 'youtubeVideoAsset.youtubeVideoTitle'),
                            'cta' => data_get($asset, 'callToActionAsset.callToAction'),
                        ];
                    }
                }
            }
        }

        return $assets;
    }

    private function googleAssetReferences(array $value): array
    {
        $references = [];
        array_walk_recursive($value, function ($item) use (&$references): void {
            if (is_string($item) && preg_match('#^customers/[^/]+/assets/[^/]+$#', $item)) {
                $references[] = $item;
            }
        });

        return array_values(array_unique($references));
    }

    private function googleEmbeddedTexts(array $ad): array
    {
        $headlines = collect();
        $descriptions = collect();
        $walk = function ($value, string $path = '') use (&$walk, $headlines, $descriptions): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $item) {
                $nextPath = strtolower($path.'.'.$key);
                if (is_array($item) && isset($item['text']) && is_string($item['text'])) {
                    str_contains($nextPath, 'description') ? $descriptions->push($item['text']) : $headlines->push($item['text']);
                } elseif (is_array($item)) {
                    $walk($item, $nextPath);
                }
            }
        };
        $walk($ad);

        return ['headlines' => $headlines, 'descriptions' => $descriptions];
    }

    private function metaCreative(array $creative): array
    {
        $story = $creative['object_story_spec'] ?? [];
        $link = $story['link_data'] ?? [];
        $video = $story['video_data'] ?? [];
        $feed = $creative['asset_feed_spec'] ?? [];
        $sources = $creative['_video_sources'] ?? [];
        $children = collect($link['child_attachments'] ?? []);
        $headlines = collect($feed['titles'] ?? [])->pluck('text')->filter();
        $descriptions = collect($feed['descriptions'] ?? [])->pluck('text')->filter();
        $bodies = collect($feed['bodies'] ?? [])->pluck('text')->filter();
        $headlines->prepend($link['name'] ?? $video['title'] ?? null);
        $descriptions->prepend($link['description'] ?? null);
        $bodies->prepend($link['message'] ?? $video['message'] ?? null);
        $media = collect();
        $appendMedia = function (string $type, ?string $preview, ?string $source = null, ?string $assetId = null) use ($media): void {
            if (! $preview && ! $source) {
                return;
            }
            $media->push(array_filter([
                'type' => $type, 'url' => $preview ?: $source, 'preview_url' => $preview,
                'source_url' => $source, 'asset_id' => $assetId, 'provider' => 'meta',
                'downloadable' => filled($source ?: $preview),
            ], fn ($value) => $value !== null && $value !== ''));
        };
        $videoId = (string) ($video['video_id'] ?? '');
        $videoSource = $sources[$videoId] ?? [];
        if ($videoId !== '') {
            $appendMedia('video', $video['image_url'] ?? $videoSource['picture'] ?? $creative['thumbnail_url'] ?? null, $videoSource['source'] ?? null, $videoId);
        } else {
            $appendMedia('image', $creative['image_url'] ?? $link['picture'] ?? $creative['thumbnail_url'] ?? null);
        }
        foreach ($children as $child) {
            $childVideoId = (string) ($child['video_id'] ?? '');
            $childSource = $sources[$childVideoId] ?? [];
            $appendMedia($childVideoId ? 'video' : 'image', $child['picture'] ?? $childSource['picture'] ?? null, $childSource['source'] ?? null, $childVideoId ?: null);
        }
        foreach ($feed['images'] ?? [] as $image) {
            $appendMedia('image', $image['url'] ?? $image['picture'] ?? null, null, $image['hash'] ?? null);
        }
        foreach ($feed['videos'] ?? [] as $feedVideo) {
            $feedVideoId = (string) ($feedVideo['video_id'] ?? '');
            $feedSource = $sources[$feedVideoId] ?? [];
            $appendMedia('video', $feedVideo['thumbnail_url'] ?? $feedSource['picture'] ?? null, $feedSource['source'] ?? null, $feedVideoId ?: null);
        }

        return array_filter([
            'provider' => 'meta', 'entity_kind' => 'ad',
            'creative_id' => $creative['id'] ?? null,
            'format' => $creative['object_type'] ?? null,
            'headlines' => $headlines->filter()->unique()->values()->all(),
            'descriptions' => $descriptions->filter()->unique()->values()->all(),
            'primary_texts' => $bodies->filter()->unique()->values()->all(),
            'cta' => data_get($link, 'call_to_action.type') ?? data_get($video, 'call_to_action.type'),
            'destination_urls' => collect([
                $link['link'] ?? data_get($link, 'call_to_action.value.link') ?? data_get($video, 'call_to_action.value.link'),
                ...$children->pluck('link')->all(),
                ...collect($feed['link_urls'] ?? [])->pluck('website_url')->all(),
            ])->filter()->unique()->values()->all(),
            'media' => $media->unique(fn (array $item) => ($item['asset_id'] ?? '').'|'.($item['source_url'] ?? $item['url'] ?? ''))->values()->all(),
            'permalink_url' => $creative['instagram_permalink_url'] ?? null,
            'story_id' => $creative['effective_object_story_id'] ?? null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function metaVideoIds(array $creative): array
    {
        $story = $creative['object_story_spec'] ?? [];

        return collect([
            data_get($story, 'video_data.video_id'),
            ...collect(data_get($story, 'link_data.child_attachments', []))->pluck('video_id')->all(),
            ...collect(data_get($creative, 'asset_feed_spec.videos', []))->pluck('video_id')->all(),
        ])->filter()->map(fn ($id) => (string) $id)->values()->all();
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
        $installTypes = ['omni_app_install', 'mobile_app_install', 'app_install'];
        $isSales = str_contains($objective, 'SALES') || str_contains($objective, 'PURCHASE');
        $isLead = str_contains($objective, 'LEAD');
        $actions = $row['actions'] ?? [];
        $actionValues = $row['action_values'] ?? [];
        $purchases = $isSales || ! $isLead ? $this->firstMetaActionValue($actions, $purchaseTypes) : 0.0;
        $leads = $isLead || ! $isSales ? $this->firstMetaActionValue($actions, $leadTypes) : 0.0;
        $installs = $this->largestMetaActionValue($actions, $installTypes);

        return [
            'conversions' => $leads + $purchases + $installs,
            'leads' => $leads,
            'purchases' => $purchases,
            'installs' => $installs,
            'conversion_value' => $purchases > 0 ? $this->firstMetaActionValue($actionValues, $purchaseTypes) : 0.0,
        ];
    }

    private function googleFallbackMetrics(AdAccount $account, array $metrics): array
    {
        $conversions = (float) ($metrics['conversions'] ?? 0);
        $objective = $account->effective_reporting_objective;
        $sales = in_array($objective, ['ecommerce', 'sales', 'purchase'], true)
            || ((float) ($metrics['conversionsValue'] ?? 0) > 0 && $objective === 'both');
        $appInstalls = $objective === 'app_installs';

        return [
            'conversions' => $conversions,
            'leads' => $sales || $appInstalls ? 0.0 : $conversions,
            'purchases' => $sales ? $conversions : 0.0,
            'installs' => (float) ($metrics['biddableAppInstallConversions'] ?? 0),
            'conversion_value' => (float) ($metrics['conversionsValue'] ?? 0),
        ];
    }

    private function googleCanonicalMetrics(AdAccount $account, array $metrics, ?array $mapped): array
    {
        $fallback = $this->googleFallbackMetrics($account, $metrics);
        if ($mapped === null) {
            return $fallback;
        }

        if (($mapped['installs'] ?? 0) <= 0) {
            $mapped['installs'] = $fallback['installs'];
        }

        return $mapped;
    }

    private function emptyCanonicalMetrics(): array
    {
        return ['conversions' => 0.0, 'leads' => 0.0, 'purchases' => 0.0, 'installs' => 0.0, 'conversion_value' => 0.0];
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

    private function largestMetaActionValue(array $actions, array $types): float
    {
        return (float) collect($actions)
            ->filter(fn ($action) => in_array((string) ($action['action_type'] ?? ''), $types, true))
            ->max(fn ($action) => (float) ($action['value'] ?? 0));
    }

    private function mappingVersion(AdAccount $account): int
    {
        return (int) (ConversionMapping::where('client_id', $account->client_id)->where('platform', $account->platform->value)->max('version') ?? 1);
    }
}
