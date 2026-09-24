<?php

namespace App\Services;

/**
 * KPI-rich snapshot persisted on reports.google_snapshot (hard-capped for JSON size).
 */
final class GoogleReportSnapshot
{
    /**
     * @param  array<string, mixed>  $data  GoogleFetchService::fetchAll payload
     * @return array<string, mixed>
     */
    public static function build(array $data): array
    {
        $agg = $data['campaign_agg'] ?? [];
        $currency = (string) (($data['customer']['currencyCode'] ?? $data['customer']['currency_code'] ?? 'USD'));

        $campaigns = collect($agg)
            ->sortByDesc(fn ($c) => (int) ($c['cost_micros'] ?? 0))
            ->values()
            ->take(150)
            ->all();

        $totalCostMicros = array_sum(array_map(fn ($c) => (int) ($c['cost_micros'] ?? 0), $agg));
        $totalClicks = array_sum(array_map(fn ($c) => (int) ($c['clicks'] ?? 0), $agg));
        $totalImpr = array_sum(array_map(fn ($c) => (int) ($c['impressions'] ?? 0), $agg));
        $totalConv = array_sum(array_map(fn ($c) => (float) ($c['conversions'] ?? 0), $agg));
        $totalConvVal = array_sum(array_map(fn ($c) => (float) ($c['conversions_value'] ?? 0), $agg));
        $spend = $totalCostMicros / 1_000_000;
        $ctr = $totalImpr > 0 ? $totalClicks / $totalImpr : 0.0;
        $cpc = $totalClicks > 0 ? $spend / $totalClicks : 0.0;
        $cpa = $totalConv > 0 ? $spend / $totalConv : 0.0;
        $roas = $spend > 0 ? $totalConvVal / $spend : 0.0;
        $convRate = $totalClicks > 0 ? $totalConv / $totalClicks : 0.0;

        $biddingMix = self::mixFromAgg($agg, 'bidding_strategy_type');
        $channelMix = self::mixFromAgg($agg, 'advertising_channel_type');

        $keywords = $data['keywords'] ?? [];
        $qsHistogram = array_fill(1, 10, 0);
        $kwRows = [];
        foreach ($keywords as $row) {
            $m = $row['metrics'] ?? [];
            $cost = ((int) ($m['costMicros'] ?? 0)) / 1_000_000;
            $qs = (int) (($row['adGroupCriterion'] ?? [])['qualityInfo']['qualityScore'] ?? 0);
            if ($qs >= 1 && $qs <= 10) {
                $qsHistogram[$qs]++;
            }
            $kw = ($row['adGroupCriterion'] ?? [])['keyword'] ?? [];
            $camp = $row['campaign'] ?? [];
            $ag = $row['adGroup'] ?? [];
            $kwRows[] = [
                'cost' => $cost,
                'row' => [
                    'keyword' => (string) ($kw['text'] ?? ''),
                    'match_type' => (string) ($kw['matchType'] ?? $kw['match_type'] ?? ''),
                    'campaign_id' => $camp['id'] ?? null,
                    'campaign' => (string) ($camp['name'] ?? ''),
                    'ad_group' => (string) ($ag['name'] ?? ''),
                    'clicks' => (int) ($m['clicks'] ?? 0),
                    'cost_micros' => (int) ($m['costMicros'] ?? 0),
                    'impressions' => (int) ($m['impressions'] ?? 0),
                    'conversions' => (float) ($m['conversions'] ?? 0),
                    'qs' => $qs,
                ],
            ];
        }
        usort($kwRows, fn ($a, $b) => $b['cost'] <=> $a['cost']);
        $keywordsTop = array_map(fn ($x) => $x['row'], array_slice($kwRows, 0, 500));

        $rsa = $data['rsa_ads'] ?? [];
        $adStrengthMix = ['POOR' => 0, 'AVERAGE' => 0, 'GOOD' => 0, 'EXCELLENT' => 0, 'UNSPECIFIED' => 0];
        $rsaFlat = [];
        foreach ($rsa as $row) {
            $st = (string) ($row['adGroupAd']['adStrength'] ?? $row['adGroupAd']['ad_strength'] ?? 'UNSPECIFIED');
            if (isset($adStrengthMix[$st])) {
                $adStrengthMix[$st]++;
            } else {
                $adStrengthMix['UNSPECIFIED']++;
            }
            $ad = $row['adGroupAd']['ad'] ?? [];
            $rsaAd = $ad['responsiveSearchAd'] ?? [];
            $h = $rsaAd['headlines'] ?? [];
            $n = is_array($h) ? count($h) : 0;
            $ag = $row['adGroup'] ?? [];
            $camp = $row['campaign'] ?? [];
            $rsaFlat[] = [
                'ad_group_id' => $ag['id'] ?? null,
                'ad_group_name' => (string) ($ag['name'] ?? ''),
                'campaign_name' => (string) ($camp['name'] ?? ''),
                'ad_id' => $ad['id'] ?? null,
                'headline_count' => $n,
                'ad_strength' => $st,
            ];
        }
        $rsaFlat = array_slice($rsaFlat, 0, 300);

        $terms = $data['search_terms'] ?? [];
        $termRows = [];
        foreach ($terms as $row) {
            $m = $row['metrics'] ?? [];
            $cost = ((int) ($m['costMicros'] ?? 0)) / 1_000_000;
            $stView = $row['searchTermView'] ?? $row['search_term_view'] ?? [];
            $termRows[] = [
                'cost' => $cost,
                'row' => [
                    'search_term' => (string) ($stView['searchTerm'] ?? $stView['search_term'] ?? ''),
                    'cost' => round($cost, 2),
                    'clicks' => (int) ($m['clicks'] ?? 0),
                    'impressions' => (int) ($m['impressions'] ?? 0),
                    'conversions' => (float) ($m['conversions'] ?? 0),
                ],
            ];
        }
        usort($termRows, fn ($a, $b) => $b['cost'] <=> $a['cost']);
        $topSearchTermsCost = array_map(fn ($x) => $x['row'], array_slice($termRows, 0, 50));
        $wasted = array_values(array_filter($termRows, fn ($x) => $x['cost'] > 10 && ($x['row']['conversions'] ?? 0) <= 0));
        usort($wasted, fn ($a, $b) => $b['cost'] <=> $a['cost']);
        $topSearchTermsWasted = array_map(fn ($x) => $x['row'], array_slice($wasted, 0, 50));

        $caRows = [];
        foreach ($data['conversion_actions'] ?? [] as $row) {
            $ca = $row['conversionAction'] ?? $row['conversion_action'] ?? [];
            if (($ca['status'] ?? '') !== 'ENABLED') {
                continue;
            }
            $name = strtolower((string) ($ca['name'] ?? ''));
            $origin = (string) ($ca['origin'] ?? '');
            if (str_contains($name, 'smart campaign') || $origin === 'SMART_CAMPAIGN') {
                continue;
            }
            $caRows[] = [
                'id' => $ca['id'] ?? null,
                'name' => (string) ($ca['name'] ?? ''),
                'category' => (string) ($ca['category'] ?? ''),
                'primary_for_goal' => (bool) ($ca['primaryForGoal'] ?? $ca['primary_for_goal'] ?? false),
                'origin' => (string) ($ca['origin'] ?? ''),
                'attribution' => (string) (($ca['attributionModelSettings']['attributionModel'] ?? $ca['attribution_model_settings']['attribution_model'] ?? '')),
                'default_value' => (float) (($ca['valueSettings']['defaultValue'] ?? $ca['value_settings']['default_value'] ?? 0)),
                'enhanced_for_leads' => (bool) ($ca['enhancedConversionsForLeadsEnabled'] ?? $ca['enhanced_conversions_for_leads_enabled'] ?? false),
            ];
        }
        $conversionActionsFull = array_slice($caRows, 0, 200);

        return [
            'date_range_literal' => $data['date_range_literal'] ?? null,
            'customer' => $data['customer'] ?? [],
            'currency' => $currency,
            'campaigns' => $campaigns,
            'counts' => [
                'campaigns' => count($agg),
                'keyword_rows' => count($keywords),
                'rsa_ads' => count($rsa),
                'conversion_actions' => count($data['conversion_actions'] ?? []),
            ],
            'kpis' => [
                'spend' => round($spend, 2),
                'clicks' => $totalClicks,
                'impressions' => $totalImpr,
                'ctr' => round($ctr, 6),
                'cpc' => round($cpc, 4),
                'conversions' => round($totalConv, 2),
                'conversion_value' => round($totalConvVal, 2),
                'cpa' => round($cpa, 4),
                'roas' => round($roas, 4),
                'conv_rate' => round($convRate, 6),
                'currency' => $currency,
            ],
            'bidding_mix' => $biddingMix,
            'channel_mix' => $channelMix,
            'qs_histogram' => $qsHistogram,
            'ad_strength_mix' => $adStrengthMix,
            'keywords' => $keywordsTop,
            'rsa_ads_flat' => $rsaFlat,
            'top_search_terms_cost' => $topSearchTermsCost,
            'top_search_terms_wasted' => $topSearchTermsWasted,
            'conversion_actions_full' => $conversionActionsFull,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $agg
     * @return list<array<string, mixed>>
     */
    private static function mixFromAgg(array $agg, string $field): array
    {
        $by = [];
        foreach ($agg as $c) {
            $k = (string) ($c[$field] ?? 'UNSPECIFIED');
            if (! isset($by[$k])) {
                $by[$k] = ['key' => $k, 'campaign_count' => 0, 'spend' => 0.0];
            }
            $by[$k]['campaign_count']++;
            $by[$k]['spend'] += ((int) ($c['cost_micros'] ?? 0)) / 1_000_000;
        }
        foreach ($by as &$row) {
            $row['spend'] = round($row['spend'], 2);
        }
        unset($row);

        return array_values($by);
    }
}
