<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\GoogleAccount;
use App\Models\MetaAccount;
use App\Services\ClientAccess;
use App\Services\GoogleAdsClientFactory;
use App\Services\GoogleFetchService;
use App\Services\MetaFetchService;
use Illuminate\Http\Request;

class InspectorController extends Controller
{
    public function fetch(Request $request)
    {
        abort_unless($request->user()->canUseDataInspector(), 403, 'You do not have permission to use Data Inspector.');

        $request->validate([
            'platform' => 'nullable|string|in:meta,google',
            'account_id' => 'required|integer',
            'account_source' => 'nullable|string|in:legacy,operational',
            'period' => 'nullable|in:last_7d,last_30d,last_90d',
        ]);

        $platform = $request->input('platform', 'meta');
        if ($platform === 'google') {
            return $this->fetchGoogle($request);
        }

        $request->validate([
            'account_id' => 'required|integer|exists:meta_accounts,id',
        ]);

        $account = MetaAccount::where('id', $request->account_id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $period = $request->period ?? 'last_30d';

        $service = new MetaFetchService(
            $account->account_id,
            $account->access_token,
            $account->app_id,
            $account->app_secret,
        );
        $service->setPeriod($period);

        try {
            $accountInfo = $service->fetchAccountInfo();
            $campaigns = $service->fetchCampaigns();
            $adsets = $service->fetchAdSets();
            $ads = $service->fetchAds();
            $adInsights = $service->fetchInsights('ad');
            $adsetInsights = $service->fetchInsights('adset');
            $campaignInsights = $service->fetchInsights('campaign');
            $pixels = $service->fetchPixels();
            $pixelStats = $service->fetchPixelStats($pixels);
            $creatives = $service->fetchCreatives($ads);
            $videoIds = $service->extractVideoIds($creatives);
            $videos = $videoIds ? $service->fetchVideoDetails($videoIds) : [];

            // Build ad insights map keyed by ad_id for easy lookup
            $adInsightsMap = [];
            foreach ($adInsights as $insight) {
                $adInsightsMap[$insight['ad_id'] ?? ''] = $insight;
            }

            // Attach insights + creative to each ad
            $adsEnriched = array_map(function ($ad) use ($adInsightsMap, $creatives, $videos) {
                $insight = $adInsightsMap[$ad['id']] ?? null;
                $creative = collect($creatives)->firstWhere('_ad_id', $ad['id']);

                // Extract video metrics from insight
                $videoMetrics = null;
                if ($insight) {
                    $allPlays = $this->extractActionValue($insight['video_play_actions'] ?? [], 'video_view');
                    $plays3s = $this->extractActionValue($insight['actions'] ?? [], 'video_view'); // 3-sec plays
                    $thruplays = $this->extractActionValue($insight['video_thruplay_watched_actions'] ?? [], 'video_view');
                    $avgWatch = $this->extractActionValue($insight['video_avg_time_watched_actions'] ?? [], 'video_view');
                    $impressions = (int) ($insight['impressions'] ?? 0);

                    if ($allPlays > 0 || $thruplays > 0) {
                        $videoMetrics = [
                            'video_play_actions' => $insight['video_play_actions'] ?? null,
                            'actions_video_view' => $plays3s,
                            'video_thruplay_watched_actions' => $insight['video_thruplay_watched_actions'] ?? null,
                            'video_avg_time_watched_actions' => $insight['video_avg_time_watched_actions'] ?? null,
                            'all_plays' => $allPlays,
                            'plays_3s' => $plays3s,
                            'thruplays' => $thruplays,
                            'avg_watch_time_sec' => $avgWatch,
                            // Hook Rate = 3-sec plays (actions[video_view]) / Impressions
                            'hook_rate' => ($impressions > 0 && $plays3s > 0) ? round($plays3s / $impressions, 4) : null,
                            // Video Play Rate = all plays / Impressions
                            'video_play_rate' => $impressions > 0 ? round($allPlays / $impressions, 4) : null,
                            'thruplay_rate' => $impressions > 0 ? round($thruplays / $impressions, 4) : null,
                        ];
                    }
                }

                // Get video duration if available
                $videoId = $creative['object_story_spec']['video_data']['video_id'] ?? null;
                $videoDuration = $videoId && isset($videos[$videoId]) ? ($videos[$videoId]['length'] ?? null) : null;

                return [
                    'id' => $ad['id'],
                    'name' => $ad['name'],
                    'status' => $ad['status'],
                    'adset_id' => $ad['adset_id'] ?? null,
                    'campaign_id' => $ad['campaign_id'] ?? null,
                    'created_time' => $ad['created_time'] ?? null,
                    'creative' => $creative ? [
                        'id' => $creative['id'] ?? null,
                        'object_type' => $creative['object_type'] ?? null,
                        'title' => $creative['title'] ?? null,
                        'body' => $creative['body'] ?? null,
                        'video_id' => $videoId,
                        'duration' => $videoDuration,
                    ] : null,
                    'insights' => $insight ? [
                        'impressions' => $insight['impressions'] ?? null,
                        'clicks' => $insight['clicks'] ?? null,
                        'spend' => $insight['spend'] ?? null,
                        'ctr' => $insight['ctr'] ?? null,
                        'cpc' => $insight['cpc'] ?? null,
                        'cpm' => $insight['cpm'] ?? null,
                        'reach' => $insight['reach'] ?? null,
                        'frequency' => $insight['frequency'] ?? null,
                        'actions' => $insight['actions'] ?? null,
                        'cost_per_action_type' => $insight['cost_per_action_type'] ?? null,
                    ] : null,
                    'video_metrics' => $videoMetrics,
                ];
            }, $ads);

            return response()->json([
                'platform' => 'meta',
                'account' => $accountInfo,
                'period' => $period,
                'campaigns' => $campaigns,
                'campaign_insights' => $campaignInsights,
                'adsets' => $adsets,
                'adset_insights' => $adsetInsights,
                'ads' => $adsEnriched,
                'pixels' => array_map(fn ($p) => array_merge($p, [
                    'stats' => collect($pixelStats)->firstWhere('pixel_id', $p['id'])['stats'] ?? [],
                ]), $pixels),
                'summary' => [
                    'total_campaigns' => count($campaigns),
                    'total_adsets' => count($adsets),
                    'total_ads' => count($ads),
                    'video_ads' => count(array_filter($adsEnriched, fn ($a) => $a['video_metrics'] !== null)),
                    'total_spend' => collect($campaignInsights)->sum(fn ($c) => (float) ($c['spend'] ?? 0)),
                    'total_impressions' => collect($campaignInsights)->sum(fn ($c) => (int) ($c['impressions'] ?? 0)),
                    'total_clicks' => collect($campaignInsights)->sum(fn ($c) => (int) ($c['clicks'] ?? 0)),
                ],
            ]);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage() !== '' ? $e->getMessage() : $e::class;

            return response()->json(['error' => $msg, 'message' => $msg], 422);
        }
    }

    private function fetchGoogle(Request $request)
    {
        // Operational ad accounts are canonical. Keeping legacy explicit prevents
        // older/cached inspector clients from accidentally querying an MCC record.
        $source = $request->input('account_source', 'operational');
        if ($source === 'operational') {
            $request->validate(['account_id' => 'required|integer|exists:ad_accounts,id']);
            $account = AdAccount::with('connection')->whereKey($request->account_id)->where('platform', 'google')->firstOrFail();
            ClientAccess::authorize($request->user(), $account->client_id);
            abort_unless($account->connection, 422, 'This ad account has no platform connection. Reconnect it from Ad Platforms.');

            $credentials = $account->connection->credentials;
            $refreshToken = $credentials['refresh_token'] ?? null;
            abort_unless($refreshToken, 422, 'This Google Ads connection has no refresh token. Reconnect it from Ad Platforms.');
            $customerId = $account->external_id;
            $loginCustomerId = $account->manager_external_id
                ?? ($credentials['login_customer_id'] ?? null)
                ?? $account->connection->manager_external_id;
        } else {
            $request->validate(['account_id' => 'required|integer|exists:google_accounts,id']);
            $account = GoogleAccount::where('id', $request->account_id)
                ->where('user_id', $request->user()->id)
                ->firstOrFail();
            $refreshToken = $account->refresh_token;
            $customerId = $account->customer_id;
            $loginCustomerId = $account->login_customer_id;
        }

        $period = $request->period ?? 'last_30d';
        $literal = GoogleFetchService::dateLiteralFromPeriod($period);

        if (! GoogleAdsClientFactory::isConfigured()) {
            $m = 'Google Ads API is not configured on the server.';

            return response()->json(['error' => $m, 'message' => $m], 503);
        }

        set_time_limit(300);
        @ini_set('memory_limit', '768M');

        try {
            $client = GoogleAdsClientFactory::fromRefreshToken(
                $refreshToken,
                $loginCustomerId
            );
            $fetch = new GoogleFetchService($client, $customerId, $literal, forInspector: true);
            $payload = $fetch->fetchAll();

            return response()->json([
                'platform' => 'google',
                'period' => $period,
                'customer_id' => $customerId,
                'payload' => $payload,
            ]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage() !== '' ? $e->getMessage() : $e::class;
            if (str_contains($msg, 'REQUESTED_METRICS_FOR_MANAGER')) {
                $msg = 'This is a Google Ads manager account, which cannot return performance metrics. Open Ad Platforms, discover its client accounts, assign the required client account, then select that client account in Data Inspector.';
            }

            return response()->json(['error' => $msg, 'message' => $msg], 422);
        }
    }

    private function extractActionValue(array $actions, string $type): float
    {
        foreach ($actions as $a) {
            if (($a['action_type'] ?? '') === $type) {
                return (float) ($a['value'] ?? 0);
            }
        }

        return 0.0;
    }
}
