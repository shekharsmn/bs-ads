<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class MetaFetchService
{
    private Client $http;
    private string $accessToken;
    private string $accountId;
    private string $appId;
    private string $appSecret;
    private string $apiVersion = 'v24.0';
    private string $baseUrl = 'https://graph.facebook.com';

    private string $period = 'last_30d';

    private const INSIGHTS_FIELDS = [
        'impressions', 'clicks', 'spend', 'ctr', 'cpc', 'cpm',
        'actions', 'cost_per_action_type', 'reach', 'frequency',
        'unique_clicks', 'inline_link_click_ctr', 'outbound_clicks',
        'video_play_actions', 'video_avg_time_watched_actions',
        'video_thruplay_watched_actions',
        'video_15_sec_watched_actions', 'video_30_sec_watched_actions',
    ];

    public function __construct(string $accountId, string $accessToken, string $appId, string $appSecret)
    {
        $this->accountId = $accountId;
        $this->accessToken = $accessToken;
        $this->appId = $appId;
        $this->appSecret = $appSecret;
        $this->http = new Client(['base_uri' => $this->baseUrl, 'timeout' => 60]);
    }

    public function setPeriod(string $period): void
    {
        $this->period = $period;
    }

    private function get(string $path, array $params = []): array
    {
        $params['access_token'] = $this->accessToken;
        try {
            $resp = $this->http->get("/{$this->apiVersion}/{$path}", ['query' => $params]);
            return json_decode($resp->getBody(), true);
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? json_decode($e->getResponse()->getBody(), true) : [];
            throw new \RuntimeException(
                'Meta API error: ' . ($body['error']['message'] ?? $e->getMessage())
            );
        }
    }

    private function paginate(string $path, array $params = []): array
    {
        $results = [];
        $params['limit'] = 200;
        $data = $this->get($path, $params);
        $results = array_merge($results, $data['data'] ?? []);
        while ($next = ($data['paging']['next'] ?? null)) {
            $resp = $this->http->get($next);
            $data = json_decode($resp->getBody(), true);
            $results = array_merge($results, $data['data'] ?? []);
        }
        return $results;
    }

    public function fetchAccountInfo(): array
    {
        return $this->get($this->accountId, [
            'fields' => 'id,name,currency,timezone_name,account_status,amount_spent',
        ]);
    }

    public function fetchCampaigns(): array
    {
        return $this->paginate("{$this->accountId}/campaigns", [
            'fields' => 'id,name,status,objective,buying_type,daily_budget,lifetime_budget,bid_strategy,special_ad_categories',
            'effective_status' => json_encode(['ACTIVE']),
        ]);
    }

    public function fetchAdSets(): array
    {
        return $this->paginate("{$this->accountId}/adsets", [
            'fields' => 'id,name,status,campaign_id,targeting,bid_strategy,billing_event,optimization_goal,daily_budget,lifetime_budget,destination_type,promoted_object',
            'effective_status' => json_encode(['ACTIVE']),
        ]);
    }

    public function fetchAds(): array
    {
        return $this->paginate("{$this->accountId}/ads", [
            'fields' => 'id,name,status,adset_id,campaign_id,creative,created_time,updated_time',
            'effective_status' => json_encode(['ACTIVE']),
        ]);
    }

    public function fetchInsights(string $level): array
    {
        $idField = match ($level) {
            'campaign' => 'campaign_id,campaign_name,objective',
            'adset'    => 'adset_id,adset_name,campaign_id,campaign_name',
            'ad'       => 'ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name',
        };
        $fields = implode(',', array_merge(self::INSIGHTS_FIELDS, [$idField]));
        return $this->paginate("{$this->accountId}/insights", [
            'level'       => $level,
            'date_preset' => $this->period,
            'fields'      => $fields,
        ]);
    }

    public function fetchCreatives(array $ads): array
    {
        $creatives = [];
        $fields = 'id,name,title,body,object_type,image_url,thumbnail_url,object_story_spec,asset_feed_spec';
        foreach ($ads as $ad) {
            $creativeId = $ad['creative']['id'] ?? null;
            if (!$creativeId) continue;
            try {
                $c = $this->get($creativeId, ['fields' => $fields]);
                $c['_ad_id'] = $ad['id'];
                $creatives[] = $c;
            } catch (\Exception) {
                // skip
            }
        }
        return $creatives;
    }

    public function fetchVideoDetails(array $videoIds): array
    {
        $videos = [];
        $fields = 'id,length,format,picture,source,title,description';
        foreach (array_slice($videoIds, 0, 50) as $vid) {
            try {
                $videos[$vid] = $this->get($vid, ['fields' => $fields]);
            } catch (\Exception) {
                $videos[$vid] = ['id' => $vid];
            }
        }
        return $videos;
    }

    public function fetchPixels(): array
    {
        return $this->paginate("{$this->accountId}/adspixels", [
            'fields' => 'id,name,last_fired_time,is_unavailable,creation_time',
        ]);
    }

    public function fetchPixelStats(array $pixels): array
    {
        $stats = [];
        foreach ($pixels as $pixel) {
            try {
                $data = $this->get("{$pixel['id']}/stats", [
                    'aggregation' => 'event_name',
                    'start_time'  => 'last_30d',
                ]);
                $normalized = array_map(fn($s) => [
                    'event_name' => $s['event_name'] ?? $s['event'] ?? '',
                    'count'      => $s['count'] ?? 0,
                ], $data['data'] ?? []);
                $stats[] = ['pixel_id' => $pixel['id'], 'stats' => $normalized];
            } catch (\Exception) {
                $stats[] = ['pixel_id' => $pixel['id'], 'stats' => []];
            }
        }
        return $stats;
    }

    public function fetchCustomConversions(): array
    {
        return $this->paginate("{$this->accountId}/customconversions", [
            'fields' => 'id,name,rule,pixel,creation_time,last_fired_time,is_unavailable,custom_event_type',
        ]);
    }

    public function fetchAudiences(): array
    {
        return $this->paginate("{$this->accountId}/customaudiences", [
            'fields' => 'id,name,subtype,approximate_count_lower_bound,approximate_count_upper_bound,data_source,delivery_status,retention_days',
        ]);
    }

    public function extractVideoIds(array $creatives): array
    {
        $ids = [];
        foreach ($creatives as $c) {
            $spec = $c['object_story_spec'] ?? [];
            if ($vid = ($spec['video_data']['video_id'] ?? null)) $ids[] = $vid;
            foreach (($c['asset_feed_spec']['videos'] ?? []) as $v) {
                if ($vid = ($v['video_id'] ?? null)) $ids[] = $vid;
            }
        }
        return array_unique($ids);
    }

    public function fetchAll(): array
    {
        $accountInfo = $this->fetchAccountInfo();
        $campaigns   = $this->fetchCampaigns();
        $adsets      = $this->fetchAdSets();
        $ads         = $this->fetchAds();

        $adInsights       = $this->fetchInsights('ad');
        $adsetInsights    = $this->fetchInsights('adset');
        $campaignInsights = $this->fetchInsights('campaign');

        $creatives = $this->fetchCreatives($ads);
        $videoIds  = $this->extractVideoIds($creatives);
        $videos    = $videoIds ? $this->fetchVideoDetails($videoIds) : [];

        $pixels           = $this->fetchPixels();
        $pixelStats       = $this->fetchPixelStats($pixels);
        $customConversions = $this->fetchCustomConversions();
        $audiences        = $this->fetchAudiences();

        $adInsightsMap       = collect($adInsights)->keyBy('ad_id')->all();
        $adsetInsightsMap    = collect($adsetInsights)->keyBy('adset_id')->all();
        $campaignInsightsMap = collect($campaignInsights)->keyBy('campaign_id')->all();

        return compact(
            'accountInfo', 'campaigns', 'adsets', 'ads',
            'adInsights', 'adInsightsMap',
            'adsetInsights', 'adsetInsightsMap',
            'campaignInsights', 'campaignInsightsMap',
            'creatives', 'videos',
            'pixels', 'pixelStats', 'customConversions', 'audiences'
        );
    }
}
