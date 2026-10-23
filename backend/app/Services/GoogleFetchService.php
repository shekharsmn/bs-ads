<?php

namespace App\Services;

use Google\Ads\GoogleAds\Lib\V23\GoogleAdsClient;
use Google\Ads\GoogleAds\V23\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V23\Services\SearchGoogleAdsStreamRequest;
use Google\Ads\GoogleAds\V23\Services\SearchGoogleAdsStreamResponse;
use Google\Protobuf\Internal\Message;

class GoogleFetchService
{
    /**
     * Extra protobuf→JSON merges only when the full-row decode left these keys empty.
     * Campaign / ad_group / metrics are merged separately when IDs are missing (see googleAdsRowToDecodedArray).
     *
     * @var list<array{0: string, 1: string}> [getter, jsonKey]
     */
    private const PROTO_MERGE_WHEN_EMPTY = [
        ['getCustomer', 'customer'],
        ['getCampaignBudget', 'campaignBudget'],
        ['getSegments', 'segments'],
        ['getAdGroupCriterion', 'adGroupCriterion'],
        ['getKeywordView', 'keywordView'],
        ['getAdGroupAd', 'adGroupAd'],
        ['getConversionAction', 'conversionAction'],
        ['getSearchTermView', 'searchTermView'],
        ['getSharedSet', 'sharedSet'],
        ['getCampaignSharedSet', 'campaignSharedSet'],
        ['getCampaignAsset', 'campaignAsset'],
        ['getAssetGroup', 'assetGroup'],
    ];

    private string $customerId;

    private string $dateLiteral;

    public function __construct(
        private readonly GoogleAdsClient $client,
        string $customerId,
        string $dateLiteral = 'LAST_30_DAYS',
        private readonly bool $forInspector = false,
    ) {
        $this->customerId = preg_replace('/\D/', '', $customerId);
        $this->dateLiteral = $dateLiteral;
    }

    public static function dateLiteralFromPeriod(string $period): string
    {
        return match ($period) {
            'last_7d' => 'LAST_7_DAYS',
            'last_90d' => 'LAST_90_DAYS',
            default => 'LAST_30_DAYS',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchCustomerRow(): array
    {
        $rows = $this->searchStream(
            'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone, '
            .'customer.auto_tagging_enabled, '
            .'customer.conversion_tracking_setting.conversion_tracking_status, '
            .'customer.conversion_tracking_setting.cross_account_conversion_tracking_id, '
            .'customer.conversion_tracking_setting.enhanced_conversions_for_leads_enabled '
            .'FROM customer LIMIT 1'
        );
        $first = $rows[0] ?? [];
        $c = $first['customer'] ?? [];
        $cts = $c['conversionTrackingSetting'] ?? [];
        $crossId = $cts['crossAccountConversionTrackingId'] ?? null;
        $status = (string) ($cts['conversionTrackingStatus'] ?? '');
        $hasCrossAccount = ($crossId !== null && $crossId !== '' && $crossId !== '0')
            || $status === 'CONVERSION_TRACKING_MANAGED_BY_ANOTHER_MANAGER';

        return [
            'id' => $c['id'] ?? null,
            'descriptive_name' => $c['descriptiveName'] ?? null,
            'currency_code' => $c['currencyCode'] ?? null,
            'time_zone' => $c['timeZone'] ?? null,
            'auto_tagging_enabled' => (bool) ($c['autoTaggingEnabled'] ?? false),
            'conversion_tracking_status' => $status !== '' ? $status : null,
            'cross_account_conversion_tracking_id' => $crossId,
            'has_cross_account_conversion_tracking' => $hasCrossAccount,
            'enhanced_conversions_for_leads_enabled' => (bool) ($cts['enhancedConversionsForLeadsEnabled'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchAll(): array
    {
        $customer = $this->fetchCustomerRow();

        $in = $this->forInspector;
        if ($in) {
            $capCampaignDaily = ' LIMIT 4000';
            $capKeywords = ' LIMIT 3000';
            $capRsa = ' LIMIT 400';
            $capSearchTerms = 600;
            $capCampaignCriterion = ' LIMIT 2000';
            $capAssetGroupAssets = ' LIMIT 1500';
            $capCampaignSharedSets = ' LIMIT 1500';
            $capCampaignAssets = ' LIMIT 2000';
            $capAssetGroups = ' LIMIT 500';
            $capSharedSets = ' LIMIT 500';
            $capConversionActions = ' LIMIT 2000';
            $dailySampleCap = 200;
        } else {
            // Queued audits: only what GoogleAuditService consumes, with tight row caps.
            $capCampaignDaily = ' LIMIT 8000';
            $capKeywords = ' LIMIT 2500';
            $capRsa = ' LIMIT 150';
            $capSearchTerms = 400;
            $capCampaignSharedSets = ' LIMIT 1200';
            $capCampaignAssets = ' LIMIT 1200';
            $capAssetGroups = ' LIMIT 500';
            $capSharedSets = ' LIMIT 500';
            $capConversionActions = ' LIMIT 400';
            $dailySampleCap = 40;
        }

        $campaignDaily = $this->searchStream(
            'SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, '
            .'campaign.bidding_strategy_type, campaign.network_settings.target_google_search, '
            .'campaign.network_settings.target_search_network, campaign.network_settings.target_content_network, '
            .'campaign.geo_target_type_setting.positive_geo_target_type, campaign.campaign_budget, '
            .'campaign_budget.amount_micros, campaign_budget.status, '
            .'metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value, '
            .'metrics.search_budget_lost_impression_share, metrics.content_budget_lost_impression_share, '
            .'metrics.cost_per_conversion, metrics.ctr, segments.date '
            .'FROM campaign '
            .'WHERE segments.date DURING '.$this->dateLiteral.' '
            ."AND campaign.status IN ('ENABLED', 'PAUSED')"
            .$capCampaignDaily
        );

        $campaignAgg = $this->aggregateCampaignMetrics($campaignDaily);

        $keywords = $this->searchStream(
            'SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, '
            .'ad_group.campaign, ad_group.id, ad_group.status, ad_group_criterion.status, '
            .'ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, '
            .'ad_group_criterion.quality_info.quality_score, '
            .'metrics.impressions, metrics.clicks, metrics.conversions, metrics.cost_micros '
            .'FROM keyword_view '
            .'WHERE segments.date DURING '.$this->dateLiteral.' '
            ."AND campaign.status IN ('ENABLED', 'PAUSED') "
            ."AND ad_group.status IN ('ENABLED', 'PAUSED') "
            ."AND ad_group_criterion.type = 'KEYWORD'"
            .$capKeywords
        );

        $rsaAds = $this->searchStream(
            'SELECT campaign.id, ad_group.campaign, ad_group.id, ad_group_ad.ad.id, ad_group_ad.status, '
            .'ad_group_ad.ad_strength, ad_group_ad.ad.type, '
            .'ad_group_ad.ad.responsive_search_ad.headlines '
            .'FROM ad_group_ad '
            ."WHERE ad_group_ad.status != 'REMOVED' "
            ."AND campaign.status IN ('ENABLED', 'PAUSED') "
            ."AND ad_group_ad.ad.type = 'RESPONSIVE_SEARCH_AD'"
            .$capRsa
        );

        if ($campaignAgg === [] && ($keywords !== [] || $rsaAds !== [])) {
            $adGroupCampaignMap = $this->fetchAdGroupCampaignMap();
            $campaignAgg = $this->campaignAggFromKeywordsAndRsa($keywords, $rsaAds, $adGroupCampaignMap);
        }

        $conversionActions = $this->searchStream(
            'SELECT conversion_action.id, conversion_action.name, conversion_action.status, '
            .'conversion_action.type, conversion_action.origin, conversion_action.category, '
            .'conversion_action.primary_for_goal, conversion_action.counting_type, '
            .'conversion_action.attribution_model_settings.attribution_model, '
            .'conversion_action.value_settings.default_value, conversion_action.value_settings.always_use_default_value '
            .'FROM conversion_action'
            .$capConversionActions
        );

        $searchTerms = $this->searchStream(
            'SELECT search_term_view.search_term, metrics.cost_micros, metrics.clicks, metrics.impressions, metrics.conversions '
            .'FROM search_term_view '
            .'WHERE segments.date DURING '.$this->dateLiteral.' '
            .'ORDER BY metrics.cost_micros DESC '
            .'LIMIT '.$capSearchTerms
        );

        $sharedSets = $this->searchStream(
            'SELECT shared_set.id, shared_set.name, shared_set.type, shared_set.status '
            .'FROM shared_set '
            ."WHERE shared_set.status != 'REMOVED'"
            .($in ? '' : $capSharedSets)
        );

        $campaignSharedSets = $this->searchStream(
            'SELECT campaign_shared_set.campaign, campaign_shared_set.shared_set '
            .'FROM campaign_shared_set'
            .$capCampaignSharedSets
        );

        $campaignAssets = $this->searchStream(
            'SELECT campaign.id, campaign_asset.field_type, campaign_asset.status '
            .'FROM campaign_asset '
            ."WHERE campaign_asset.status != 'REMOVED'"
            .$capCampaignAssets
        );

        $assetGroups = $this->searchStream(
            'SELECT asset_group.id, asset_group.name, asset_group.campaign, asset_group.ad_strength, '
            .'campaign.advertising_channel_type '
            .'FROM asset_group '
            ."WHERE campaign.advertising_channel_type = 'PERFORMANCE_MAX'"
            .$capAssetGroups
        );

        $customerConversionGoals = [];
        $campaignCriteria = [];
        $assetGroupAssets = [];
        $biddingStrategies = [];

        if ($in) {
            $customerConversionGoals = $this->searchStream(
                'SELECT customer_conversion_goal.category, customer_conversion_goal.origin, customer_conversion_goal.biddable '
                .'FROM customer_conversion_goal'
            );

            $campaignCriteria = $this->searchStream(
                'SELECT campaign.id, campaign_criterion.type, campaign_criterion.negative, '
                .'campaign_criterion.keyword.text, campaign_criterion.keyword.match_type, '
                .'campaign_criterion.youtube_video.video_id, campaign_criterion.ad_schedule.start_hour, '
                .'campaign_criterion.ad_schedule.end_hour, campaign_criterion.ad_schedule.day_of_week '
                .'FROM campaign_criterion '
                ."WHERE campaign.status != 'REMOVED'"
                .$capCampaignCriterion
            );

            $assetGroupAssets = $this->searchStream(
                'SELECT asset_group.id, asset_group_asset.field_type, asset_group_asset.asset '
                .'FROM asset_group_asset'
                .$capAssetGroupAssets
            );

            $biddingStrategies = $this->searchStream(
                'SELECT bidding_strategy.id, bidding_strategy.name, bidding_strategy.type, bidding_strategy.status '
                .'FROM bidding_strategy '
                ."WHERE bidding_strategy.status != 'REMOVED'"
            );
        }

        $payload = [
            'customer' => $customer,
            'date_range_literal' => $this->dateLiteral,
            'campaign_agg' => $campaignAgg,
            'campaign_daily_sample' => array_slice($campaignDaily, 0, $dailySampleCap),
            'keywords' => $keywords,
            'rsa_ads' => $rsaAds,
            'conversion_actions' => $conversionActions,
            'customer_conversion_goals' => $customerConversionGoals,
            'search_terms' => $searchTerms,
            'campaign_criteria' => $campaignCriteria,
            'shared_sets' => $sharedSets,
            'campaign_shared_sets' => $campaignSharedSets,
            'campaign_assets' => $campaignAssets,
            'asset_groups' => $assetGroups,
            'asset_group_assets' => $assetGroupAssets,
            'bidding_strategies' => $biddingStrategies,
        ];

        if ($this->forInspector) {
            $payload['_data_inspector'] = [
                'preview' => true,
                'note' => 'Inspector mode returns extra GAQL slices (conversion goals, criteria, bidding strategies, asset group assets) for debugging. Queued audits omit those — they are not used by the checklist scorer.',
            ];
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $dailyRows
     * @return array<string, array<string, mixed>>
     */
    private function aggregateCampaignMetrics(array $dailyRows): array
    {
        $byId = [];
        foreach ($dailyRows as $row) {
            $camp = $row['campaign'] ?? [];
            $id = $this->campaignIdFromSearchRow($row);
            if ($id === '') {
                continue;
            }
            $m = $row['metrics'] ?? [];
            $ns = $camp['networkSettings'] ?? [];
            $geo = $camp['geoTargetTypeSetting'] ?? [];
            $cb = $row['campaignBudget'] ?? [];
            if (! isset($byId[$id])) {
                $byId[$id] = [
                    'id' => $id,
                    'name' => $camp['name'] ?? null,
                    'status' => $camp['status'] ?? null,
                    'advertising_channel_type' => $camp['advertisingChannelType'] ?? null,
                    'bidding_strategy_type' => $camp['biddingStrategyType'] ?? null,
                    'target_google_search' => $ns['targetGoogleSearch'] ?? null,
                    'target_search_network' => $ns['targetSearchNetwork'] ?? null,
                    'target_content_network' => $ns['targetContentNetwork'] ?? null,
                    'positive_geo_target_type' => $geo['positiveGeoTargetType'] ?? null,
                    'campaign_budget' => $camp['campaignBudget'] ?? null,
                    'budget_amount_micros' => (int) ($cb['amountMicros'] ?? 0),
                    'budget_status' => $cb['status'] ?? null,
                    'cost_micros' => 0,
                    'impressions' => 0,
                    'clicks' => 0,
                    'conversions' => 0.0,
                    'conversions_value' => 0.0,
                    'max_search_budget_lost_is' => null,
                    'max_content_budget_lost_is' => null,
                ];
            }
            $byId[$id]['cost_micros'] += (int) ($m['costMicros'] ?? 0);
            $byId[$id]['impressions'] += (int) ($m['impressions'] ?? 0);
            $byId[$id]['clicks'] += (int) ($m['clicks'] ?? 0);
            $byId[$id]['conversions'] += (float) ($m['conversions'] ?? 0);
            $byId[$id]['conversions_value'] += (float) ($m['conversionsValue'] ?? 0);
            $sbl = $m['searchBudgetLostImpressionShare'] ?? null;
            if ($sbl !== null && $sbl !== '') {
                $v = (float) $sbl;
                $byId[$id]['max_search_budget_lost_is'] = max($byId[$id]['max_search_budget_lost_is'] ?? 0, $v);
            }
            $cbl = $m['contentBudgetLostImpressionShare'] ?? null;
            if ($cbl !== null && $cbl !== '') {
                $v = (float) $cbl;
                $byId[$id]['max_content_budget_lost_is'] = max($byId[$id]['max_content_budget_lost_is'] ?? 0, $v);
            }
        }

        return $byId;
    }

    /**
     * Stable campaign id from a decoded `campaign` slice (protobuf JSON uses int64; PHP may coerce without JSON_BIGINT_AS_STRING).
     */
    private function campaignIdFromSlice(array $slice): string
    {
        $id = $slice['id'] ?? null;
        if (is_string($id) && preg_match('/^\d+$/', $id)) {
            return $id;
        }
        if (is_int($id)) {
            return (string) $id;
        }
        if (is_float($id) && floor($id) === $id) {
            return sprintf('%.0f', $id);
        }
        $rn = (string) ($slice['resourceName'] ?? $slice['resource_name'] ?? '');
        if ($rn !== '' && preg_match('#/campaigns/(\d+)$#', $rn, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * Ad group id from a decoded `ad_group` slice (same id rules as campaign; resourceName may end with /adGroups/{id}).
     */
    private function adGroupIdFromSlice(array $slice): string
    {
        $id = $slice['id'] ?? null;
        if (is_string($id) && preg_match('/^\d+$/', $id)) {
            return $id;
        }
        if (is_int($id)) {
            return (string) $id;
        }
        if (is_float($id) && floor($id) === $id) {
            return sprintf('%.0f', $id);
        }
        $rn = (string) ($slice['resourceName'] ?? $slice['resource_name'] ?? '');
        if ($rn !== '' && preg_match('#/adGroups/(\d+)$#', $rn, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * Campaign id from an `ad_group.campaign` style resource reference string.
     */
    private function campaignIdFromResourceReference(mixed $ref): string
    {
        if (! is_string($ref) || $ref === '') {
            return '';
        }
        if (preg_match('#/campaigns/(\d+)$#', $ref, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowNested(array $row, string $camel, string $snake): array
    {
        $v = $row[$camel] ?? $row[$snake] ?? null;

        return is_array($v) ? $v : [];
    }

    /**
     * Ad group id embedded in keyword_view.resource_name: customers/.../keywordViews/{adGroupId}~{criterionId}
     */
    private function adGroupIdFromKeywordViewRow(array $row): string
    {
        $kv = $this->rowNested($row, 'keywordView', 'keyword_view');
        $rn = (string) ($kv['resourceName'] ?? $kv['resource_name'] ?? '');
        if ($rn !== '' && preg_match('#/keywordViews/(\d+)~#', $rn, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * @return array<string, string> ad_group_id => campaign_id
     */
    private function fetchAdGroupCampaignMap(): array
    {
        $cap = $this->forInspector ? ' LIMIT 8000' : ' LIMIT 12000';
        $rows = $this->searchStream(
            'SELECT ad_group.id, ad_group.campaign FROM ad_group '
            ."WHERE ad_group.status != 'REMOVED'"
            .$cap
        );
        $map = [];
        foreach ($rows as $row) {
            $ag = $this->rowNested($row, 'adGroup', 'ad_group');
            if ($ag === []) {
                continue;
            }
            $aid = $this->adGroupIdFromSlice($ag);
            $cid = $this->campaignIdFromResourceReference($ag['campaign'] ?? null);
            if ($aid !== '' && $cid !== '') {
                $map[$aid] = $cid;
            }
        }

        return $map;
    }

    /**
     * Resolve campaign id from a search stream row (campaign slice, ad_group.campaign, ad_group id map, or keyword_view resource name + map).
     *
     * @param  array<string, string>  $adGroupToCampaign
     */
    private function campaignIdFromSearchRow(array $row, array $adGroupToCampaign = []): string
    {
        $camp = $this->rowNested($row, 'campaign', 'campaign');
        if ($camp !== []) {
            $id = $this->campaignIdFromSlice($camp);
            if ($id !== '') {
                return $id;
            }
        }
        $ag = $this->rowNested($row, 'adGroup', 'ad_group');
        if ($ag !== []) {
            $cid = $this->campaignIdFromResourceReference($ag['campaign'] ?? null);
            if ($cid !== '') {
                return $cid;
            }
            $aid = $this->adGroupIdFromSlice($ag);
            if ($aid !== '' && isset($adGroupToCampaign[$aid])) {
                return $adGroupToCampaign[$aid];
            }
        }
        if ($adGroupToCampaign !== []) {
            $aid = $this->adGroupIdFromKeywordViewRow($row);
            if ($aid !== '' && isset($adGroupToCampaign[$aid])) {
                return $adGroupToCampaign[$aid];
            }
            $aga = $this->rowNested($row, 'adGroupAd', 'ad_group_ad');
            $agRef = $aga['adGroup'] ?? $aga['ad_group'] ?? null;
            if (is_string($agRef) && preg_match('#/adGroups/(\d+)$#', $agRef, $m)) {
                $aid = $m[1];
                if (isset($adGroupToCampaign[$aid])) {
                    return $adGroupToCampaign[$aid];
                }
            }
        }

        return '';
    }

    /**
     * When the campaign + segments.date stream yields no rows but keyword_view / ad_group_ad still return data,
     * rebuild per-campaign metrics so audits and snapshots are not empty.
     *
     * @param  array<int, array<string, mixed>>  $keywords
     * @param  array<int, array<string, mixed>>  $rsaAds
     * @param  array<string, string>  $adGroupToCampaign
     * @return array<string, array<string, mixed>>
     */
    private function campaignAggFromKeywordsAndRsa(array $keywords, array $rsaAds, array $adGroupToCampaign): array
    {
        $shell = function (string $id, ?string $name, mixed $status, mixed $channel): array {
            return [
                'id' => $id,
                'name' => $name,
                'status' => $status,
                'advertising_channel_type' => $channel,
                'bidding_strategy_type' => null,
                'target_google_search' => null,
                'target_search_network' => null,
                'target_content_network' => null,
                'positive_geo_target_type' => null,
                'campaign_budget' => null,
                'budget_amount_micros' => 0,
                'budget_status' => null,
                'cost_micros' => 0,
                'impressions' => 0,
                'clicks' => 0,
                'conversions' => 0.0,
                'conversions_value' => 0.0,
                'max_search_budget_lost_is' => null,
                'max_content_budget_lost_is' => null,
            ];
        };

        $byId = [];
        foreach ($keywords as $row) {
            $id = $this->campaignIdFromSearchRow($row, $adGroupToCampaign);
            if ($id === '') {
                continue;
            }
            $camp = $this->rowNested($row, 'campaign', 'campaign');
            $m = $this->rowNested($row, 'metrics', 'metrics');
            if (! isset($byId[$id])) {
                $byId[$id] = $shell(
                    $id,
                    isset($camp['name']) ? (string) $camp['name'] : null,
                    $camp['status'] ?? null,
                    $camp['advertisingChannelType'] ?? $camp['advertising_channel_type'] ?? null
                );
            }
            $byId[$id]['cost_micros'] += (int) ($m['costMicros'] ?? $m['cost_micros'] ?? 0);
            $byId[$id]['impressions'] += (int) ($m['impressions'] ?? 0);
            $byId[$id]['clicks'] += (int) ($m['clicks'] ?? 0);
            $byId[$id]['conversions'] += (float) ($m['conversions'] ?? 0);
            $byId[$id]['conversions_value'] += (float) ($m['conversionsValue'] ?? $m['conversions_value'] ?? 0);
        }

        foreach ($rsaAds as $row) {
            $id = $this->campaignIdFromSearchRow($row, $adGroupToCampaign);
            if ($id === '') {
                continue;
            }
            $camp = $this->rowNested($row, 'campaign', 'campaign');
            if (! isset($byId[$id])) {
                $byId[$id] = $shell(
                    $id,
                    isset($camp['name']) ? (string) $camp['name'] : null,
                    $camp['status'] ?? null,
                    $camp['advertisingChannelType'] ?? $camp['advertising_channel_type'] ?? null
                );
            }
        }

        return $byId;
    }

    /**
     * @param  ?int  $maxRows  Hard stop after N rows (safety net if GAQL has no LIMIT)
     * @return array<int, array<string, mixed>>
     */
    private function searchStream(string $query, ?int $maxRows = null): array
    {
        $svc = $this->client->getGoogleAdsServiceClient();
        $request = SearchGoogleAdsStreamRequest::build($this->customerId, $query);
        $out = [];
        preg_match('/\bFROM\s+([a-z_]+)/i', $query, $resourceMatch);
        $resource = $resourceMatch[1] ?? 'unknown resource';

        try {
            foreach ($svc->searchStream($request)->readAll() as $chunk) {
                $queue = [$chunk];
                while ($queue !== []) {
                    $node = array_shift($queue);
                    if (! is_object($node)) {
                        continue;
                    }
                    // Batched stream responses must be expanded first (never pass these to row decoding).
                    if ($this->isSearchGoogleAdsStreamResponse($node)) {
                        foreach ($node->getResults() as $child) {
                            $queue[] = $child;
                        }

                        continue;
                    }
                    if ($this->isGoogleAdsRowObject($node)) {
                        $out[] = $this->googleAdsRowToDecodedArray($node);
                        if ($maxRows !== null && count($out) >= $maxRows) {
                            break 2;
                        }

                        continue;
                    }

                    throw new \RuntimeException(
                        'Unexpected Google Ads searchStream node: '.(is_object($node) ? $node::class : gettype($node))
                    );
                }
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("Google Ads {$resource} query failed: ".$e->getMessage(), previous: $e);
        }

        return $out;
    }

    private function isSearchGoogleAdsStreamResponse(object $o): bool
    {
        return $o instanceof SearchGoogleAdsStreamResponse
            || str_ends_with($o::class, '\\SearchGoogleAdsStreamResponse')
            || str_ends_with($o::class, 'SearchGoogleAdsStreamResponse');
    }

    private function isGoogleAdsRowObject(object $o): bool
    {
        if ($this->isSearchGoogleAdsStreamResponse($o)) {
            return false;
        }

        return $o instanceof GoogleAdsRow
            || str_ends_with($o::class, '\\GoogleAdsRow')
            || str_ends_with($o::class, 'GoogleAdsRow');
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function decodedRowCampaignNeedsProtoMerge(array $decoded): bool
    {
        $c = $decoded['campaign'] ?? null;
        if (! is_array($c) || $c === []) {
            return true;
        }

        return $this->campaignIdFromSlice($c) === '';
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function decodedRowAdGroupNeedsProtoMerge(array $decoded): bool
    {
        $ag = $decoded['adGroup'] ?? null;
        if (! is_array($ag) || $ag === []) {
            return true;
        }
        if ($this->campaignIdFromResourceReference($ag['campaign'] ?? null) !== '') {
            return false;
        }

        return $this->adGroupIdFromSlice($ag) === '';
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function mergeProtoSubmessageIf(object $row, array &$decoded, string $getter, string $jsonKey, callable $when): void
    {
        if (! $when($decoded)) {
            return;
        }
        if (! method_exists($row, $getter)) {
            return;
        }
        $msg = $row->{$getter}();
        if (! $msg instanceof Message) {
            return;
        }
        $slice = $this->messageToArray($msg);
        if ($slice === []) {
            return;
        }
        $decoded[$jsonKey] = array_replace_recursive($decoded[$jsonKey] ?? [], $slice);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function mergeProtoSubmessageIfEmpty(object $row, array &$decoded, string $getter, string $jsonKey): void
    {
        $this->mergeProtoSubmessageIf($row, $decoded, $getter, $jsonKey, fn (array $d) => empty($d[$jsonKey]));
    }

    /**
     * @param  mixed  $row  Protobuf {@see GoogleAdsRow} only (never a {@see SearchGoogleAdsStreamResponse} batch).
     * @return array<string, mixed>
     */
    private function googleAdsRowToDecodedArray(mixed $row): array
    {
        if (! is_object($row)) {
            throw new \InvalidArgumentException('Expected object GoogleAdsRow, got: '.gettype($row));
        }
        if ($this->isSearchGoogleAdsStreamResponse($row)) {
            throw new \InvalidArgumentException(
                'googleAdsRowToDecodedArray received SearchGoogleAdsStreamResponse; unwrap the stream in searchStream() first.'
            );
        }
        if (! $this->isGoogleAdsRowObject($row)) {
            throw new \InvalidArgumentException(
                'Expected a GoogleAdsRow, got: '.$row::class
            );
        }
        $jsonFlags = JSON_BIGINT_AS_STRING;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $decoded = json_decode($row->serializeToJsonString(), true, 8192, $jsonFlags);
        if (! is_array($decoded)) {
            $decoded = [];
        }

        $this->mergeProtoSubmessageIf($row, $decoded, 'getCampaign', 'campaign', [$this, 'decodedRowCampaignNeedsProtoMerge']);
        $this->mergeProtoSubmessageIf($row, $decoded, 'getAdGroup', 'adGroup', [$this, 'decodedRowAdGroupNeedsProtoMerge']);
        $this->mergeProtoSubmessageIf($row, $decoded, 'getMetrics', 'metrics', fn (array $d) => empty($d['metrics']));

        foreach (self::PROTO_MERGE_WHEN_EMPTY as [$getter, $jsonKey]) {
            $this->mergeProtoSubmessageIfEmpty($row, $decoded, $getter, $jsonKey);
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function messageToArray(Message $message): array
    {
        $jsonFlags = JSON_BIGINT_AS_STRING;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        return json_decode($message->serializeToJsonString(), true, 8192, $jsonFlags) ?? [];
    }
}
