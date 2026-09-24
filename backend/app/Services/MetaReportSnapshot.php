<?php

namespace App\Services;

/**
 * Compact Meta account snapshot for report drill-down (capped).
 */
final class MetaReportSnapshot
{
    /**
     * @param  array<string, mixed>  $data  MetaFetchService::fetchAll
     * @return array<string, mixed>
     */
    public static function build(array $data): array
    {
        $adInsightsMap = $data['adInsightsMap'] ?? [];
        $totalSpend = array_sum(array_map(fn ($i) => (float) ($i['spend'] ?? 0), $adInsightsMap));
        $totalImpr = array_sum(array_map(fn ($i) => (float) ($i['impressions'] ?? 0), $adInsightsMap));
        $pixels = $data['pixels'] ?? [];
        $campaigns = array_slice($data['campaigns'] ?? [], 0, 80);
        $adsets = array_slice($data['adsets'] ?? [], 0, 80);
        $audiences = array_slice($data['audiences'] ?? [], 0, 50);
        $creatives = $data['creatives'] ?? [];
        $formatMix = [];
        foreach ($creatives as $c) {
            $t = strtoupper($c['object_type'] ?? 'UNKNOWN');
            $formatMix[$t] = ($formatMix[$t] ?? 0) + 1;
        }
        $topAds = [];
        foreach (array_slice($data['ads'] ?? [], 0, 400) as $ad) {
            $aid = $ad['id'] ?? null;
            $ins = $adInsightsMap[$aid] ?? [];
            $topAds[] = [
                'ad_id' => $aid,
                'name' => (string) ($ad['name'] ?? ''),
                'spend' => round((float) ($ins['spend'] ?? 0), 2),
                'frequency' => isset($ins['frequency']) ? round((float) $ins['frequency'], 3) : null,
                'ctr' => isset($ins['ctr']) ? round((float) $ins['ctr'], 4) : null,
                'impressions' => isset($ins['impressions']) ? (int) round((float) $ins['impressions']) : null,
            ];
        }
        usort($topAds, fn ($a, $b) => $b['spend'] <=> $a['spend']);
        $topAds = array_slice($topAds, 0, 40);
        $pixelSlim = array_map(fn ($p) => [
            'id' => $p['id'] ?? null,
            'name' => (string) ($p['name'] ?? ''),
            'last_fired_time' => $p['last_fired_time'] ?? null,
        ], array_slice($pixels, 0, 15));

        return [
            'kpis' => [
                'spend' => round($totalSpend, 2),
                'impressions' => (int) round($totalImpr),
            ],
            'pixel_count' => count($pixels),
            'pixels' => $pixelSlim,
            'campaigns_sample' => $campaigns,
            'adsets_sample' => $adsets,
            'audiences_sample' => $audiences,
            'custom_conversions_count' => count($data['customConversions'] ?? []),
            'creative_format_mix' => $formatMix,
            'top_ads_by_spend' => $topAds,
        ];
    }
}
