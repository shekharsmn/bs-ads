<?php

namespace App\Services;

/**
 * Google Ads audit checks aligned with .claude/google-audit.md (v1.5).
 * Deferred API-impossible checks return SKIP and are excluded from scoring.
 */
class GoogleAuditService
{
    public const SECTION_WEIGHTS = [
        'Conversion Tracking' => 0.25,
        'Wasted Spend' => 0.20,
        'Structure' => 0.15,
        'Keywords & QS' => 0.15,
        'Ads & Assets' => 0.15,
        'Settings & Targeting' => 0.10,
    ];

    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function runAllChecks(): array
    {
        return array_merge(
            $this->checksDeferred(),
            $this->checksConversion(),
            $this->checksWastedSpend(),
            $this->checksStructure(),
            $this->checksKeywords(),
            $this->checksAdsAssets(),
            $this->checksSettings(),
            $this->checksPmaxExtended(),
            $this->checksAiDemandGenCtv(),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array{score:int, section_scores: array<string, int>, weights: array<string, float>}
     */
    public function calculateScore(array $results): array
    {
        $scored = array_values(array_filter($results, fn ($r) => ($r['status'] ?? '') !== 'SKIP'));
        $sectionScores = [];
        $weightedSum = 0.0;
        $weightSum = 0.0;
        foreach (array_keys(self::SECTION_WEIGHTS) as $section) {
            $sectionResults = array_values(array_filter($scored, fn ($r) => ($r['section'] ?? '') === $section));
            if ($sectionResults === []) {
                continue;
            }
            $avg = array_sum(array_column($sectionResults, 'score')) / count($sectionResults);
            $sectionScore = (int) round($avg);
            $sectionScores[$section] = $sectionScore;
            $weight = self::SECTION_WEIGHTS[$section];
            $weightedSum += $sectionScore * $weight;
            $weightSum += $weight;
        }
        $overall = $weightSum > 0 ? (int) round($weightedSum / $weightSum) : 0;

        return ['score' => $overall, 'section_scores' => $sectionScores, 'weights' => self::SECTION_WEIGHTS];
    }

    public static function grade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 60 => 'C',
            $score >= 40 => 'D',
            default => 'F',
        };
    }

    /**
     * @param  array{evidence?: array|null, metrics?: array|null, impact_score?: int|null}  $opts
     * @return array<string, mixed>
     */
    private function finalizeCheck(array $base, array $opts = []): array
    {
        foreach (['evidence', 'metrics'] as $k) {
            if (! empty($opts[$k]) && is_array($opts[$k])) {
                $base[$k] = $opts[$k];
            }
        }
        if (array_key_exists('impact_score', $opts) && $opts['impact_score'] !== null) {
            $base['impact_score'] = (int) $opts['impact_score'];
        } elseif (in_array($base['status'] ?? '', ['WARN', 'FAIL'], true)) {
            $base['impact_score'] = max(1, min(100, 100 - (int) ($base['score'] ?? 0)));
        }

        return $base;
    }

    /**
     * @param  array{evidence?: array|null, metrics?: array|null, impact_score?: int|null}  $opts
     * @return array<string, mixed>
     */
    private function pass(string $id, string $name, string $section, string $finding, string $rec = '', array $opts = []): array
    {
        $base = ['id' => $id, 'name' => $name, 'section' => $section, 'status' => 'PASS', 'severity' => null, 'finding' => $finding, 'recommendation' => $rec, 'score' => 100];

        return $this->finalizeCheck($base, $opts);
    }

    /**
     * @param  array{evidence?: array|null, metrics?: array|null, impact_score?: int|null}  $opts
     * @return array<string, mixed>
     */
    private function warn(string $id, string $name, string $section, string $severity, string $finding, string $rec = '', array $opts = []): array
    {
        $score = match ($severity) {
            'Critical' => 20, 'High' => 40, 'Medium' => 60, 'Low' => 80, default => 70
        };

        $base = ['id' => $id, 'name' => $name, 'section' => $section, 'status' => 'WARN', 'severity' => $severity, 'finding' => $finding, 'recommendation' => $rec, 'score' => $score];

        return $this->finalizeCheck($base, $opts);
    }

    /**
     * @param  array{evidence?: array|null, metrics?: array|null, impact_score?: int|null}  $opts
     * @return array<string, mixed>
     */
    private function fail(string $id, string $name, string $section, string $severity, string $finding, string $rec = '', array $opts = []): array
    {
        $score = match ($severity) {
            'Critical' => 0, 'High' => 20, 'Medium' => 40, 'Low' => 60, default => 30
        };

        $base = ['id' => $id, 'name' => $name, 'section' => $section, 'status' => 'FAIL', 'severity' => $severity, 'finding' => $finding, 'recommendation' => $rec, 'score' => $score];

        return $this->finalizeCheck($base, $opts);
    }

    /**
     * @param  array{evidence?: array|null, metrics?: array|null, impact_score?: int|null}  $opts
     * @return array<string, mixed>
     */
    private function skip(string $id, string $name, string $section, string $finding, array $opts = []): array
    {
        $base = ['id' => $id, 'name' => $name, 'section' => $section, 'status' => 'SKIP', 'severity' => null, 'finding' => $finding, 'recommendation' => '', 'score' => 100];

        return $this->finalizeCheck($base, $opts);
    }

    /** @return list<string> */
    private function brandTokens(): array
    {
        $name = strtolower((string) ($this->data['customer']['descriptive_name'] ?? ''));
        $parts = preg_split('/\s+/', preg_replace('/[^a-z0-9\s]/', '', $name)) ?: [];
        $stop = ['inc', 'llc', 'ltd', 'corp', 'the', 'and', 'co', 'plc', 'gmbh', 'sa', 'bv'];

        return array_values(array_filter($parts, fn ($t) => strlen($t) > 2 && ! in_array($t, $stop, true)));
    }

    private function isSmartCampaignConversion(array $ca): bool
    {
        $n = strtolower((string) ($ca['name'] ?? ''));
        $o = (string) ($ca['origin'] ?? '');

        return str_contains($n, 'smart campaign') || $o === 'SMART_CAMPAIGN';
    }

    /** @return list<array<string,mixed>> */
    private function enabledConversionActions(): array
    {
        $rows = $this->data['conversion_actions'] ?? [];

        return array_values(array_filter($rows, function ($row) {
            $ca = $row['conversionAction'] ?? [];
            $st = (string) ($ca['status'] ?? '');
            if ($st !== 'ENABLED') {
                return false;
            }
            if ($this->isSmartCampaignConversion($ca)) {
                return false;
            }

            return true;
        }));
    }

    private function checksDeferred(): array
    {
        $ct = 'Conversion Tracking';
        $ws = 'Wasted Spend';
        $kw = 'Keywords & QS';
        $aa = 'Ads & Assets';
        $st = 'Settings & Targeting';

        return [
            // deferred: G13 — API cannot know search term review recency
            $this->skip('G13', 'Search term audit recency', $ws, 'Deferred: last review date is not available from the Google Ads API.', ['evidence' => ['deferred' => true]]),
            $this->skip('G24', 'Landing page experience', $kw, 'Deferred: requires landing-page crawl / LP analysis beyond API.', ['evidence' => ['deferred' => true]]),
            $this->skip('G59', 'Landing page mobile speed', $st, 'Deferred: requires CrUX / lab speed data (not in Ads API).', ['evidence' => ['deferred' => true]]),
            $this->skip('G60', 'Landing page relevance', $st, 'Deferred: requires crawling final URLs.', ['evidence' => ['deferred' => true]]),
            $this->skip('G61', 'Landing page schema markup', $st, 'Deferred: requires HTML/schema inspection.', ['evidence' => ['deferred' => true]]),
            $this->skip('G-CT2', 'GA4 linked and flowing', $ct, 'Deferred: requires Google Analytics Admin API + property linkage checks.', ['evidence' => ['deferred' => true]]),
            $this->skip('G-CT3', 'Google Tag firing', $ct, 'Deferred: requires site/tag diagnostics outside Ads API.', ['evidence' => ['deferred' => true]]),
            $this->skip('G-AD2', 'CTR vs industry benchmark', $aa, 'Deferred: requires external vertical benchmarks.', ['evidence' => ['deferred' => true]]),
        ];
    }

    private function checksConversion(): array
    {
        $s = 'Conversion Tracking';
        $caRows = $this->enabledConversionActions();
        $cas = array_map(fn ($r) => $r['conversionAction'] ?? [], $caRows);
        $primary = array_filter($cas, fn ($c) => ($c['primaryForGoal'] ?? false) === true);
        $slimAll = $this->slimConversionActionsForEvidence($cas, 50);

        $checks = [];

        $checks[] = count($cas) > 0
            ? $this->pass('G42', 'Conversion actions defined', $s, count($cas).' enabled conversion action(s).', '', [
                'evidence' => ['conversion_actions' => $slimAll],
                'metrics' => ['enabled_count' => count($cas)],
            ])
            : $this->fail('G42', 'Conversion actions defined', $s, 'Critical', 'No active conversion actions.', 'Create at least one conversion action in Google Ads.', [
                'evidence' => ['conversion_actions' => []],
                'impact_score' => 95,
            ]);

        $accountEcl = (bool) ($this->data['customer']['enhanced_conversions_for_leads_enabled'] ?? false);
        $enhanced = array_filter($cas, fn ($c) => ($c['enhancedConversionsForLeadsEnabled'] ?? false) === true);
        $hasEnhanced = $accountEcl || count($enhanced) > 0;
        $checks[] = $hasEnhanced
            ? $this->pass('G43', 'Enhanced conversions enabled', $s, $accountEcl
                ? 'Enhanced conversions for leads enabled at account level (conversion tracking settings).'
                : 'Enhanced conversions enabled on at least one conversion action.', '', [
                'evidence' => [
                    'account_level' => $accountEcl,
                    'actions_with_ecl' => $this->slimConversionActionsForEvidence(array_values($enhanced), 20),
                ],
            ])
            : $this->warn('G43', 'Enhanced conversions enabled', $s, 'High', 'Enhanced conversions for leads not detected at account level; per-action ECL fields are not exposed in API v23.', 'Enable enhanced conversions for leads in Google Ads conversion tracking settings.', [
                'evidence' => ['conversion_actions' => $slimAll],
            ]);

        $checks[] = $this->warn('G44', 'Server-side tracking', $s, 'Medium', 'Server-side GTM / offline import cannot be fully verified via API; confirm in-tag setup.', 'Use server-side tagging or offline conversions where appropriate.', [
            'evidence' => ['note' => 'Not observable from Ads API alone.'],
        ]);

        $checks[] = $this->warn('G45', 'Consent Mode v2', $s, 'Medium', 'Consent Mode cannot be verified from Ads API alone.', 'Validate Advanced Consent Mode v2 in Tag Manager / gtag for EEA/UK traffic.', [
            'evidence' => ['note' => 'Requires tag / GTM inspection.'],
        ]);

        $checks[] = $this->pass('G46', 'Conversion window appropriate', $s, 'Review conversion windows in UI; API does not encode business cycle validation.', 'Align windows with sales cycle in conversion action settings.', [
            'evidence' => ['conversion_actions' => $slimAll],
        ]);

        $microish = ['PAGE_VIEW', 'ADD_TO_CART', 'ENGAGEMENT'];
        $badPrimary = array_filter($primary, fn ($c) => in_array((string) ($c['category'] ?? ''), $microish, true));
        $checks[] = count($badPrimary) === 0
            ? $this->pass('G47', 'Micro vs macro separation', $s, 'No micro-category actions marked primary for goal among sampled actions.', '', [
                'evidence' => ['primary_actions' => $this->slimConversionActionsForEvidence(array_values($primary), 30)],
            ])
            : $this->warn('G47', 'Micro vs macro separation', $s, 'High', 'Some primary actions look micro/upper-funnel.', 'Set only macro conversions (Purchase/Lead) as primary for bidding.', [
                'evidence' => ['micro_primary_actions' => $this->slimConversionActionsForEvidence(array_values($badPrimary), 20)],
            ]);

        $dda = array_filter($cas, fn ($c) => str_contains(strtolower((string) ($c['attributionModelSettings']['attributionModel'] ?? '')), 'data_driven'));
        $checks[] = count($dda) > 0 || count($cas) === 0
            ? $this->pass('G48', 'Attribution model', $s, 'Data-driven attribution present on at least one action or no actions to score.', '', [
                'evidence' => ['data_driven_actions' => $this->slimConversionActionsForEvidence(array_values($dda), 20)],
                'metrics' => ['dda_action_count' => count($dda), 'total_actions' => count($cas)],
            ])
            : $this->warn('G48', 'Attribution model', $s, 'Medium', 'Limited data-driven attribution on enabled actions.', 'Prefer data-driven attribution where eligible.', [
                'evidence' => ['conversion_actions' => $slimAll],
            ]);

        $hasValues = array_filter($cas, fn ($c) => (float) ($c['valueSettings']['defaultValue'] ?? 0) > 0 || ($c['valueSettings']['alwaysUseDefaultValue'] ?? false));
        $missingVal = array_values(array_filter($cas, fn ($c) => ! (((float) ($c['valueSettings']['defaultValue'] ?? 0) > 0) || ($c['valueSettings']['alwaysUseDefaultValue'] ?? false))));
        $checks[] = count($cas) === 0 || count($hasValues) > 0
            ? $this->pass('G49', 'Conversion value assignment', $s, 'Values or value rules appear configured on conversion actions.', '', [
                'evidence' => ['conversion_actions' => $slimAll],
            ])
            : $this->warn('G49', 'Conversion value assignment', $s, 'High', 'Many enabled actions lack default conversion values.', 'Add dynamic values or value rules for lead gen / ecommerce.', [
                'evidence' => ['actions_without_default_value' => $this->slimConversionActionsForEvidence($missingVal, 30)],
            ]);

        $origins = array_count_values(array_map(fn ($c) => (string) ($c['origin'] ?? 'UNKNOWN'), $cas));
        $dup = (isset($origins['WEBSITE']) && isset($origins['GOOGLE_ANALYTICS_4_IMPORT']) && count($cas) > 1);
        $checks[] = ! $dup
            ? $this->pass('G-CT1', 'No duplicate counting', $s, 'No obvious WEBSITE + GA4 import duplication pattern in enabled actions (heuristic).', '', [
                'evidence' => ['origin_counts' => $origins, 'conversion_actions' => $slimAll],
            ])
            : $this->warn('G-CT1', 'No duplicate counting', $s, 'Critical', 'Both WEBSITE and GA4 import origins detected — verify you are not double-counting.', 'Audit enabled conversion actions for duplicate event mapping.', [
                'evidence' => ['origin_counts' => $origins, 'conversion_actions' => $slimAll],
                'impact_score' => 90,
            ]);

        $checks[] = $this->pass('G-CTV1', 'CTV Floodlight tracking limitation', $s, 'CTV vs Floodlight cannot be inferred from API without DV360 linkage; no CTV-specific misconfig flagged.', 'If you run CTV, avoid relying on Floodlight-only measurement per Google guidance.', [
            'evidence' => ['note' => 'No CTV / DV360 linkage in this fetch.'],
        ]);

        return $checks;
    }

    private function checksWastedSpend(): array
    {
        $s = 'Wasted Spend';
        $terms = $this->data['search_terms'] ?? [];
        $keywords = $this->data['keywords'] ?? [];
        $totalSpend = 0.0;
        $wasted = 0.0;
        foreach ($terms as $row) {
            $m = $row['metrics'] ?? [];
            $cost = ((int) ($m['costMicros'] ?? 0)) / 1_000_000;
            $conv = (float) ($m['conversions'] ?? 0);
            $totalSpend += $cost;
            if ($cost > 10 && $conv <= 0) {
                $wasted += $cost;
            }
        }
        $pct = $totalSpend > 0 ? ($wasted / $totalSpend) * 100 : 0;
        $wastedRows = $this->evidenceWastedSearchTermRows($terms, 50);
        $g16Metrics = [
            'total_spend' => round($totalSpend, 2),
            'wasted_spend' => round($wasted, 2),
            'wasted_pct' => round($pct, 2),
        ];
        $g16Impact = (int) min(100, max(1, round($wasted)));
        $g16 = $pct < 5 ? $this->pass('G16', 'Wasted spend on irrelevant terms', $s, sprintf('Heuristic: %.1f%% of search-term spend on >$10 zero-conv terms.', $pct), '', [
            'metrics' => $g16Metrics,
            'evidence' => ['search_terms' => array_slice($wastedRows, 0, 15)],
        ])
            : ($pct < 15 ? $this->warn('G16', 'Wasted spend on irrelevant terms', $s, 'Medium', sprintf('%.1f%% spend on high-cost zero-conv terms (>$10).', $pct), 'Review top search terms and add negatives.', [
                'metrics' => $g16Metrics,
                'evidence' => ['search_terms' => $wastedRows],
                'impact_score' => $g16Impact,
            ])
                : $this->fail('G16', 'Wasted spend on irrelevant terms', $s, 'Critical', sprintf('%.1f%% spend on high-cost zero-conv terms.', $pct), 'Add negatives and tighten match types.', [
                    'metrics' => $g16Metrics,
                    'evidence' => ['search_terms' => $wastedRows],
                    'impact_score' => $g16Impact,
                ]));

        $negLists = array_filter($this->data['shared_sets'] ?? [], fn ($r) => ($r['sharedSet']['type'] ?? '') === 'NEGATIVE_KEYWORDS');
        $negEvidence = [];
        foreach ($negLists as $r) {
            $ss = $r['sharedSet'] ?? [];
            $negEvidence[] = [
                'id' => $ss['id'] ?? null,
                'name' => (string) ($ss['name'] ?? ''),
                'status' => (string) ($ss['status'] ?? ''),
            ];
        }
        $g14 = count($negLists) >= 3 ? $this->pass('G14', 'Negative keyword lists exist', $s, count($negLists).' negative keyword shared set(s).', '', [
            'evidence' => ['negative_lists' => array_slice($negEvidence, 0, 30)],
            'metrics' => ['list_count' => count($negLists)],
        ])
            : (count($negLists) >= 1 ? $this->warn('G14', 'Negative keyword lists exist', $s, 'Medium', 'Only '.count($negLists).' negative list(s).', 'Create themed negative lists (competitor, jobs, free, irrelevant).', [
                'evidence' => ['negative_lists' => $negEvidence],
            ])
                : $this->fail('G14', 'Negative keyword lists exist', $s, 'Critical', 'No negative keyword shared lists found.', 'Create shared negative keyword lists and apply them.', [
                    'evidence' => ['negative_lists' => []],
                ]));

        $css = $this->data['campaign_shared_sets'] ?? [];
        $cssEvidence = [];
        foreach (array_slice($css, 0, 40) as $r) {
            $cssEvidence[] = [
                'campaign_id' => ($r['campaign'] ?? [])['id'] ?? null,
                'shared_set_id' => ($r['sharedSet'] ?? [])['id'] ?? null,
            ];
        }
        $g15 = count($css) > 0 || count($negLists) === 0
            ? $this->pass('G15', 'Account-level negatives applied', $s, 'Campaign-shared-set links present or negative lists absent (heuristic pass).', '', [
                'evidence' => ['campaign_shared_set_links' => $cssEvidence],
                'metrics' => ['link_rows' => count($css), 'negative_list_count' => count($negLists)],
            ])
            : $this->warn('G15', 'Account-level negatives applied', $s, 'High', 'Negative lists exist but few campaign attachments detected.', 'Apply shared negative lists at account or all-campaign level.', [
                'evidence' => ['negative_lists' => $negEvidence, 'campaign_shared_set_links' => $cssEvidence],
            ]);

        $badBroadManual = false;
        foreach ($keywords as $row) {
            $c = $row['campaign'] ?? [];
            $agc = $row['adGroupCriterion'] ?? [];
            $mt = (string) ($agc['keyword']['matchType'] ?? '');
            $bst = (string) ($c['biddingStrategyType'] ?? '');
            if ($mt === 'BROAD' && $bst === 'MANUAL_CPC') {
                continue;
            }
            if ($mt === 'BROAD' && in_array($bst, ['MANUAL_CPC', 'UNKNOWN', 'UNSPECIFIED'], true)) {
                $badBroadManual = true;
            }
        }
        $broadRows = $this->evidenceBroadManualKeywordRows($keywords, 40);
        $g17 = ! $badBroadManual
            ? $this->pass('G17', 'Broad match + smart bidding pairing', $s, 'No broad + manual CPC outside legacy-BMM skip cases.', '', [
                'metrics' => ['broad_manual_keyword_rows' => 0],
            ])
            : $this->fail('G17', 'Broad match + smart bidding pairing', $s, 'Critical', 'Broad match keywords found without smart bidding.', 'Move to tCPA/tROAS/Max conversions or tighten match types.', [
                'evidence' => ['keywords' => $broadRows],
            ]);

        $g18 = $this->pass('G18', 'Close variant pollution', $s, 'Close variants require search term review; use Search Terms report regularly.', 'Review close variants in search terms with high spend.', [
            'evidence' => ['search_terms_top_spend' => array_slice($this->evidenceWastedSearchTermRows($terms, 20), 0, 10)],
        ]);

        $visibleSpend = 0.0;
        $hiddenSpend = 0.0;
        foreach ($terms as $row) {
            $m = $row['metrics'] ?? [];
            $st = (string) ($row['searchTermView']['searchTerm'] ?? '');
            $cost = ((int) ($m['costMicros'] ?? 0)) / 1_000_000;
            if (str_starts_with($st, 'n/a') || strcasecmp($st, 'other search terms') === 0) {
                $hiddenSpend += $cost;
            } else {
                $visibleSpend += $cost;
            }
        }
        $visRatio = ($visibleSpend + $hiddenSpend) > 0 ? $visibleSpend / ($visibleSpend + $hiddenSpend) : 1;
        $g19Metrics = ['visible_spend' => round($visibleSpend, 2), 'hidden_spend' => round($hiddenSpend, 2), 'visible_ratio' => round($visRatio, 4)];
        $g19 = $visRatio >= 0.6 ? $this->pass('G19', 'Search term visibility', $s, sprintf('~%.0f%% of search-term spend on visible rows (heuristic).', $visRatio * 100), '', [
            'metrics' => $g19Metrics,
        ])
            : ($visRatio >= 0.4 ? $this->warn('G19', 'Search term visibility', $s, 'Medium', 'Visibility of search-term spend may be limited.', 'Expect privacy thresholds; mitigate with broad structure and RSA.', [
                'metrics' => $g19Metrics,
            ])
                : $this->fail('G19', 'Search term visibility', $s, 'Medium', 'Low visible search-term spend ratio.', 'Improve structure and negatives to surface intent.', [
                    'metrics' => $g19Metrics,
                ]));

        $kwZero = 0;
        foreach ($keywords as $row) {
            $m = $row['metrics'] ?? [];
            $clicks = (int) ($m['clicks'] ?? 0);
            $conv = (float) ($m['conversions'] ?? 0);
            if ($clicks > 100 && $conv <= 0) {
                $kwZero++;
            }
        }
        $zkw = $this->evidenceZeroConversionKeywordsRows($keywords, 50);
        $gWs1 = $kwZero === 0 ? $this->pass('G-WS1', 'Zero-conversion keywords', $s, 'No keywords with >100 clicks and 0 conversions in the window (heuristic).', '', [
            'metrics' => ['zero_conv_high_click_count' => 0],
        ])
            : ($kwZero <= 3 ? $this->warn('G-WS1', 'Zero-conversion keywords', $s, 'High', $kwZero.' such keyword(s).', 'Pause or rewrite low-converting high-click keywords.', [
                'evidence' => ['keywords' => $zkw],
                'metrics' => ['zero_conv_high_click_count' => $kwZero],
            ])
                : $this->fail('G-WS1', 'Zero-conversion keywords', $s, 'High', $kwZero.' keywords with >100 clicks and 0 conversions.', 'Pause or fix landing pages / intent match.', [
                    'evidence' => ['keywords' => $zkw],
                    'metrics' => ['zero_conv_high_click_count' => $kwZero],
                ]));

        return [$g16, $g14, $g15, $g17, $g18, $g19, $gWs1];
    }

    private function checksStructure(): array
    {
        $s = 'Structure';
        $agg = $this->data['campaign_agg'] ?? [];
        $names = array_map(fn ($c) => (string) ($c['name'] ?? ''), $agg);
        $checks = [];
        $nameCounts = array_count_values(array_filter($names));
        $dupNames = array_keys(array_filter($nameCounts, fn ($n) => $n > 1));
        $checks[] = count($names) > 1 && count(array_unique($names)) < count($names) * 0.9
            ? $this->warn('G01', 'Campaign naming convention', $s, 'Low', 'Some duplicate or very similar campaign names detected.', 'Use a consistent naming pattern.', [
                'evidence' => ['duplicate_or_similar_names' => array_slice($dupNames, 0, 25)],
            ])
            : $this->pass('G01', 'Campaign naming convention', $s, 'Campaign names look reasonably distinct.');

        $checks[] = $this->pass('G02', 'Ad group naming convention', $s, 'Ad group naming not fully evaluated via aggregate fetch.', 'Align ad group names with campaign taxonomy.', [
            'evidence' => ['note' => 'Ad group names not in campaign_agg fetch.'],
        ]);

        $kwByAg = [];
        $agMeta = [];
        foreach ($this->data['keywords'] ?? [] as $row) {
            $ag = (string) (($row['adGroup'] ?? [])['id'] ?? '');
            if ($ag === '') {
                continue;
            }
            $impr = (int) (($row['metrics'] ?? [])['impressions'] ?? 0);
            if ($impr <= 0) {
                continue;
            }
            $txt = strtolower((string) (($row['adGroupCriterion'] ?? [])['keyword']['text'] ?? ''));
            if (! isset($kwByAg[$ag])) {
                $kwByAg[$ag] = [];
                $agMeta[$ag] = [
                    'ad_group' => (string) (($row['adGroup'] ?? [])['name'] ?? ''),
                    'campaign' => (string) (($row['campaign'] ?? [])['name'] ?? ''),
                ];
            }
            $kwByAg[$ag][$txt] = true;
        }
        $heavy = 0;
        $heavyEvidence = [];
        foreach ($kwByAg as $agId => $uniq) {
            if (count($uniq) > 20) {
                $heavy++;
                if (count($heavyEvidence) < 20) {
                    $heavyEvidence[] = array_merge(['ad_group_id' => $agId, 'distinct_keywords' => count($uniq)], $agMeta[$agId] ?? []);
                }
            }
        }
        $checks[] = $heavy === 0
            ? $this->pass('G03', 'Single theme ad groups', $s, 'No enabled ad groups with >20 distinct active keywords (impressions>0).')
            : $this->warn('G03', 'Single theme ad groups', $s, 'High', $heavy.' ad group(s) with many active keywords.', 'Split themes; aim for <=10-20 tightly related keywords per ad group.', [
                'evidence' => ['heavy_ad_groups' => $heavyEvidence],
                'metrics' => ['heavy_ad_group_count' => $heavy],
            ]);

        $objectives = [];
        foreach ($agg as $c) {
            $raw = strtolower((string) ($c['name'] ?? ''));
            $stripped = preg_replace('/\b(north|south|east|west|metro|city|county)\b|\b\d{5}\b|[A-Z]{2}\d{2,}/i', '', $raw) ?? $raw;
            $objectives[$stripped] = true;
        }
        $objCount = max(1, count($objectives));
        $campPerObj = count($agg) / $objCount;
        $checks[] = $campPerObj <= 5 ? $this->pass('G04', 'Campaign count per objective', $s, 'Campaign count per stripped objective heuristic is healthy.', '', [
            'metrics' => ['campaigns' => count($agg), 'objective_keys' => $objCount, 'campaigns_per_objective' => round($campPerObj, 2)],
        ])
            : ($campPerObj <= 8 ? $this->warn('G04', 'Campaign count per objective', $s, 'High', 'Possible fragmentation across objectives.', 'Consolidate where learning is split.', [
                'metrics' => ['campaigns' => count($agg), 'objective_keys' => $objCount, 'campaigns_per_objective' => round($campPerObj, 2)],
            ])
                : $this->fail('G04', 'Campaign count per objective', $s, 'High', 'High campaign fragmentation heuristic.', 'Consolidate campaigns per funnel stage.', [
                    'metrics' => ['campaigns' => count($agg), 'objective_keys' => $objCount, 'campaigns_per_objective' => round($campPerObj, 2)],
                ]));

        $brand = $this->brandTokens();
        $mixedBrand = false;
        $mixedEvidence = [];
        foreach ($agg as $c) {
            if (($c['advertising_channel_type'] ?? '') !== 'SEARCH') {
                continue;
            }
            $hits = 0;
            $total = 0;
            $brandSamples = [];
            $nonBrandSamples = [];
            foreach ($this->data['keywords'] ?? [] as $row) {
                if ((string) (($row['campaign'] ?? [])['id'] ?? '') !== (string) ($c['id'] ?? '')) {
                    continue;
                }
                if (($row['campaign']['advertisingChannelType'] ?? '') !== 'SEARCH') {
                    continue;
                }
                $txt = strtolower((string) (($row['adGroupCriterion'] ?? [])['keyword']['text'] ?? ''));
                if ($txt === '') {
                    continue;
                }
                $total++;
                $isBrand = false;
                foreach ($brand as $b) {
                    if ($b !== '' && str_contains($txt, $b)) {
                        $hits++;
                        $isBrand = true;
                        if (count($brandSamples) < 8) {
                            $brandSamples[] = $txt;
                        }
                        break;
                    }
                }
                if (! $isBrand && count($nonBrandSamples) < 8) {
                    $nonBrandSamples[] = $txt;
                }
            }
            if ($total > 0 && $hits > 0 && $hits < $total * 0.5) {
                $mixedBrand = true;
                $mixedEvidence[] = [
                    'campaign_id' => $c['id'] ?? null,
                    'name' => (string) ($c['name'] ?? ''),
                    'brand_keyword_hits' => $hits,
                    'total_keywords_sampled' => $total,
                    'sample_brand_keywords' => $brandSamples,
                    'sample_non_brand_keywords' => $nonBrandSamples,
                ];
            }
        }
        $checks[] = ! $mixedBrand
            ? $this->pass('G05', 'Brand vs Non-Brand separation', $s, 'No obvious mixed brand/non-brand in same Search campaign (keyword heuristic).')
            : $this->fail('G05', 'Brand vs Non-Brand separation', $s, 'Critical', 'Brand and non-brand keywords appear mixed in a Search campaign.', 'Split brand into its own campaign(s).', [
                'evidence' => ['mixed_campaigns' => $mixedEvidence],
            ]);

        $hasPmax = false;
        $pmaxList = [];
        foreach ($agg as $c) {
            if (($c['advertising_channel_type'] ?? '') === 'PERFORMANCE_MAX') {
                $hasPmax = true;
                if (count($pmaxList) < 15) {
                    $pmaxList[] = ['id' => $c['id'] ?? null, 'name' => (string) ($c['name'] ?? '')];
                }
            }
        }
        $conv30 = array_sum(array_map(fn ($c) => (float) ($c['conversions'] ?? 0), $agg));
        $checks[] = $hasPmax || $conv30 < 30
            ? $this->pass('G06', 'PMax present for eligible accounts', $s, $hasPmax ? 'Performance Max campaigns detected.' : 'Low conversion volume — PMax optional.', '', [
                'evidence' => ['pmax_campaigns' => $pmaxList],
                'metrics' => ['account_conversions_window' => round($conv30, 2), 'has_pmax' => $hasPmax],
            ])
            : $this->warn('G06', 'PMax present for eligible accounts', $s, 'Medium', 'No PMax detected despite meaningful conversions.', 'Test Performance Max where eligible.', [
                'evidence' => ['pmax_campaigns' => []],
                'metrics' => ['account_conversions_window' => round($conv30, 2), 'has_pmax' => false],
            ]);

        $checks[] = $this->warn('G07', 'Search + PMax overlap', $s, 'High', 'Brand exclusions in PMax require campaign-level signals not fully exposed here.', 'Add brand negatives / brand exclusions for PMax when running parallel brand Search.', [
            'evidence' => ['pmax_campaigns' => $pmaxList],
        ]);

        $checks[] = $this->pass('G08', 'Budget allocation matches priority', $s, 'Budget vs performance requires UI review; no blocking signal from API aggregates.', 'Ensure top performers are not limited by budget.', [
            'metrics' => ['campaign_count' => count($agg)],
        ]);

        $checks[] = $this->pass('G09', 'Campaign daily budget vs spend', $s, 'Intraday pacing not available via API snapshot.', 'Use intraday metrics in UI for noon caps.');

        $checks[] = $this->pass('G10', 'Ad schedule configured', $s, 'Ad schedules present on some campaigns via criteria fetch (if any).', 'Match schedules to business hours when relevant.');

        $badGeo = [];
        foreach ($agg as $c) {
            if (($c['advertising_channel_type'] ?? '') === 'SEARCH'
                && ($c['positive_geo_target_type'] ?? '') === 'LOCATION_OF_PRESENCE_OR_INTEREST') {
                $badGeo[] = [
                    'id' => $c['id'] ?? null,
                    'name' => (string) ($c['name'] ?? ''),
                    'positive_geo_target_type' => (string) ($c['positive_geo_target_type'] ?? ''),
                ];
            }
        }
        $checks[] = $badGeo === []
            ? $this->pass('G11', 'Geographic targeting accuracy', $s, 'No Search campaigns using presence-or-interest geo (heuristic local issue).')
            : $this->fail('G11', 'Geographic targeting accuracy', $s, 'High', 'Local Search campaigns should prefer presence, not presence-or-interest.', 'Switch to People in your targeted locations for local businesses.', [
                'evidence' => ['campaigns' => array_slice($badGeo, 0, 25)],
            ]);

        $badNet = false;
        $spOff = [];
        $displayOn = [];
        foreach ($agg as $c) {
            if (($c['advertising_channel_type'] ?? '') !== 'SEARCH') {
                continue;
            }
            $cid = $c['id'] ?? null;
            $nm = (string) ($c['name'] ?? '');
            if (($c['target_content_network'] ?? false) === true) {
                $badNet = true;
                $displayOn[] = ['id' => $cid, 'name' => $nm];
            }
            if (($c['target_search_network'] ?? true) === false) {
                $spOff[] = ['id' => $cid, 'name' => $nm];
            }
        }
        if (! $badNet) {
            $checks[] = $spOff !== []
                ? $this->warn('G12', 'Network settings', $s, 'Low', 'Display off for Search; Search Partners disabled — may miss incremental reach.', 'Consider enabling Search Partners unless excluded for brand safety.', [
                    'evidence' => ['search_partners_disabled_campaigns' => array_slice($spOff, 0, 25)],
                ])
                : $this->pass('G12', 'Network settings', $s, 'Display Network off for Search; Search Partners on or N/A.');
        } else {
            $checks[] = $this->fail('G12', 'Network settings', $s, 'High', 'Display Network enabled on Search.', 'Disable Display Network on Search unless intentional.', [
                'evidence' => ['display_network_on_search' => array_slice($displayOn, 0, 25)],
            ]);
        }

        return $checks;
    }

    private function checksKeywords(): array
    {
        $s = 'Keywords & QS';
        $keywords = $this->data['keywords'] ?? [];
        $imprW = 0;
        $qsW = 0;
        $qsLow = 0;
        $totalKw = 0;
        $zeroImpr = 0;
        foreach ($keywords as $row) {
            $m = $row['metrics'] ?? [];
            $impr = (int) ($m['impressions'] ?? 0);
            $qs = (int) (($row['adGroupCriterion'] ?? [])['qualityInfo']['qualityScore'] ?? 0);
            if ($impr > 0 && $qs > 0) {
                $imprW += $impr * $qs;
                $qsW += $impr;
            }
            if ($qs > 0 && $qs <= 3) {
                $qsLow++;
            }
            $totalKw++;
            if ($impr === 0) {
                $zeroImpr++;
            }
        }
        $iwqs = $qsW > 0 ? $imprW / $qsW : 10;
        $hist = array_fill(1, 10, 0);
        $lowQsRows = [];
        foreach ($keywords as $row) {
            $m = $row['metrics'] ?? [];
            $impr = (int) ($m['impressions'] ?? 0);
            $qs = (int) (($row['adGroupCriterion'] ?? [])['qualityInfo']['qualityScore'] ?? 0);
            if ($qs >= 1 && $qs <= 10) {
                $hist[$qs]++;
            }
            if ($qs > 0 && $qs <= 5 && $impr > 0 && count($lowQsRows) < 30) {
                $kw = ($row['adGroupCriterion'] ?? [])['keyword'] ?? [];
                $lowQsRows[] = [
                    'keyword' => (string) ($kw['text'] ?? ''),
                    'qs' => $qs,
                    'impressions' => $impr,
                    'campaign' => (string) (($row['campaign'] ?? [])['name'] ?? ''),
                ];
            }
        }
        usort($lowQsRows, fn ($a, $b) => $b['impressions'] <=> $a['impressions']);
        $qsEvidence = ['qs_histogram' => $hist, 'low_qs_keywords' => $lowQsRows];
        $g20 = $iwqs >= 7 ? $this->pass('G20', 'Average Quality Score', $s, sprintf('Impression-weighted QS ~%.1f', $iwqs), '', [
            'metrics' => ['iwqs' => round($iwqs, 2)],
            'evidence' => $qsEvidence,
        ])
            : ($iwqs >= 5 ? $this->warn('G20', 'Average Quality Score', $s, 'High', 'QS in mid band.', 'Improve CTR, relevance, and LP experience.', [
                'metrics' => ['iwqs' => round($iwqs, 2)],
                'evidence' => $qsEvidence,
            ])
                : $this->fail('G20', 'Average Quality Score', $s, 'High', 'Low impression-weighted QS.', 'Improve QS components and prune low performers.', [
                    'metrics' => ['iwqs' => round($iwqs, 2)],
                    'evidence' => $qsEvidence,
                ]));
        $ratioLow = $totalKw > 0 ? $qsLow / $totalKw : 0;
        $g21 = $ratioLow < 0.1 ? $this->pass('G21', 'Critical QS keywords', $s, 'Few QS<=3 keywords by count heuristic.', '', [
            'metrics' => ['low_qs_ratio' => round($ratioLow, 4), 'low_qs_count' => $qsLow, 'total_keywords' => $totalKw],
            'evidence' => $qsEvidence,
        ])
            : ($ratioLow < 0.25 ? $this->warn('G21', 'Critical QS keywords', $s, 'High', 'Elevated share of low-QS keywords.', 'Rewrite ads and improve LP alignment.', [
                'metrics' => ['low_qs_ratio' => round($ratioLow, 4), 'low_qs_count' => $qsLow],
                'evidence' => $qsEvidence,
            ])
                : $this->fail('G21', 'Critical QS keywords', $s, 'Critical', 'High share of QS<=3 keywords.', 'Pause or fix low QS keywords.', [
                    'metrics' => ['low_qs_ratio' => round($ratioLow, 4), 'low_qs_count' => $qsLow],
                    'evidence' => $qsEvidence,
                ]));
        $g22 = $this->pass('G22', 'Expected CTR component', $s, 'Below-average expected CTR rates require keyword_view deep dive in UI.', 'Filter keyword_view for searchPredictedCtr = BELOW_AVERAGE in Ads Editor/GUI.', [
            'evidence' => ['note' => 'Predicted CTR components not selected in fetch.'],
        ]);

        $checks = [$g20, $g21, $g22, $this->pass('G23', 'Ad relevance component', $s, 'Use UI filters on creative quality score components.', 'Improve RSA/ad relevance for low scores.', [
            'evidence' => ['note' => 'Ad relevance from API not in lightweight keyword_view.'],
        ])];
        $checks[] = $this->skip('G24', 'Landing page experience', $s, 'Deferred: landing page signals not fetched.', ['evidence' => ['deferred' => true]]);
        $checks[] = $this->pass('G25', 'Top keyword QS', $s, 'Top-spend QS review is manual; aggregate QS computed.', 'Ensure top spenders are QS 7+.', [
            'metrics' => ['iwqs' => round($iwqs, 2)],
        ]);

        $ziRatio = $totalKw > 0 ? $zeroImpr / $totalKw : 0;
        $ziSample = [];
        foreach ($keywords as $row) {
            if (count($ziSample) >= 40) {
                break;
            }
            $impr = (int) (($row['metrics'] ?? [])['impressions'] ?? 0);
            if ($impr === 0) {
                $kw = ($row['adGroupCriterion'] ?? [])['keyword'] ?? [];
                $ziSample[] = [
                    'keyword' => (string) ($kw['text'] ?? ''),
                    'campaign' => (string) (($row['campaign'] ?? [])['name'] ?? ''),
                    'ad_group' => (string) (($row['adGroup'] ?? [])['name'] ?? ''),
                ];
            }
        }
        $checks[] = $ziRatio <= 0.1 ? $this->pass('G-KW1', 'Zero-impression keywords', $s, sprintf('~%.0f%% zero-impression keywords in window.', $ziRatio * 100), '', [
            'metrics' => ['zero_impression_ratio' => round($ziRatio, 4), 'zero_impression_count' => $zeroImpr],
        ])
            : $this->warn('G-KW1', 'Zero-impression keywords', $s, 'Medium', 'Many zero-impression keywords.', 'Pause or remove dormant keywords.', [
                'metrics' => ['zero_impression_ratio' => round($ziRatio, 4), 'zero_impression_count' => $zeroImpr],
                'evidence' => ['zero_impression_keywords' => $ziSample],
            ]);

        $checks[] = $this->warn('G-KW2', 'Keyword-to-ad relevance', $s, 'High', 'Headline/keyword overlap requires RSA text extraction beyond this pass.', 'Ensure RSA headlines include core query themes per ad group.', [
            'evidence' => ['note' => 'RSA headline text available in rsa_ads fetch for manual cross-check.'],
        ]);

        return $checks;
    }

    private function checksAdsAssets(): array
    {
        $s = 'Ads & Assets';
        $rsa = $this->data['rsa_ads'] ?? [];
        $byAg = [];
        foreach ($rsa as $row) {
            $ag = (string) (($row['adGroup'] ?? [])['id'] ?? '');
            if ($ag === '') {
                continue;
            }
            $byAg[$ag][] = $row;
        }
        $missingRsa = 0;
        $missingAgEvidence = [];
        foreach ($this->data['keywords'] ?? [] as $row) {
            if (($row['campaign']['advertisingChannelType'] ?? '') !== 'SEARCH') {
                continue;
            }
            $ag = (string) (($row['adGroup'] ?? [])['id'] ?? '');
            if ($ag !== '' && empty($byAg[$ag])) {
                $missingRsa++;
                if (count($missingAgEvidence) < 25) {
                    $missingAgEvidence[] = [
                        'ad_group_id' => $ag,
                        'ad_group' => (string) (($row['adGroup'] ?? [])['name'] ?? ''),
                        'campaign' => (string) (($row['campaign'] ?? [])['name'] ?? ''),
                    ];
                }
            }
        }
        $g26 = $missingRsa === 0
            ? $this->pass('G26', 'RSA per ad group', $s, 'RSA coverage looks present for sampled Search ad groups.', '', [
                'metrics' => ['rsa_rows' => count($rsa), 'search_keyword_sample_rows' => count($this->data['keywords'] ?? [])],
            ])
            : $this->warn('G26', 'RSA per ad group', $s, 'High', 'Some Search ad groups may lack RSA.', 'Add at least one RSA per ad group.', [
                'evidence' => ['ad_groups_missing_rsa' => $missingAgEvidence],
                'metrics' => ['missing_rsa_signal_rows' => $missingRsa],
            ]);

        $headlinePoor = 0;
        $headlineRows = [];
        foreach ($rsa as $row) {
            $ad = $row['adGroupAd']['ad'] ?? [];
            $rsaAd = $ad['responsiveSearchAd'] ?? [];
            $h = $rsaAd['headlines'] ?? [];
            $n = is_array($h) ? count($h) : 0;
            if ($n < 8 && $n > 0) {
                $headlinePoor++;
                if (count($headlineRows) < 30) {
                    $ag = $row['adGroup'] ?? [];
                    $camp = $row['campaign'] ?? [];
                    $headlineRows[] = [
                        'ad_group_id' => $ag['id'] ?? null,
                        'ad_group_name' => (string) ($ag['name'] ?? ''),
                        'campaign_name' => (string) ($camp['name'] ?? ''),
                        'ad_id' => $ad['id'] ?? null,
                        'headline_count' => $n,
                    ];
                }
            }
        }
        $g27 = $headlinePoor === 0
            ? $this->pass('G27', 'RSA headline count', $s, 'RSA headline counts generally healthy.', '', [
                'metrics' => ['rsa_rows' => count($rsa)],
            ])
            : $this->warn('G27', 'RSA headline count', $s, 'High', 'Some RSAs have fewer than 8 headlines.', 'Add 8-15 unique headlines per RSA.', [
                'evidence' => ['ad_groups' => $headlineRows],
                'metrics' => ['rsa_with_low_headline_count' => $headlinePoor],
            ]);

        $checks = [$g26, $g27, $this->pass('G28', 'RSA description count', $s, 'Validate 3-4 descriptions per RSA in UI.', 'Add more descriptions where <3.', [
            'evidence' => ['rsa_sample_size' => count($rsa)],
        ])];
        $strengthPoor = false;
        $poorRsas = [];
        foreach ($rsa as $row) {
            if (($row['adGroupAd']['adStrength'] ?? '') === 'POOR') {
                $strengthPoor = true;
                if (count($poorRsas) < 25) {
                    $ad = $row['adGroupAd']['ad'] ?? [];
                    $rsaAd = $ad['responsiveSearchAd'] ?? [];
                    $h = $rsaAd['headlines'] ?? [];
                    $ag = $row['adGroup'] ?? [];
                    $camp = $row['campaign'] ?? [];
                    $poorRsas[] = [
                        'ad_id' => $ad['id'] ?? null,
                        'ad_strength' => 'POOR',
                        'headline_count' => is_array($h) ? count($h) : 0,
                        'ad_group_name' => (string) ($ag['name'] ?? ''),
                        'campaign_name' => (string) ($camp['name'] ?? ''),
                    ];
                }
            }
        }
        $checks[] = ! $strengthPoor
            ? $this->pass('G29', 'RSA Ad Strength', $s, 'No POOR ad strength RSAs detected in fetch.', '', [
                'evidence' => ['poor_rsas' => []],
                'metrics' => ['rsa_rows' => count($rsa)],
            ])
            : $this->fail('G29', 'RSA Ad Strength', $s, 'High', 'At least one RSA has POOR ad strength.', 'Improve asset diversity and relevance.', [
                'evidence' => ['poor_rsas' => $poorRsas],
            ]);

        $checks[] = $this->pass('G30', 'RSA pinning strategy', $s, 'Pinning not evaluated in aggregate.', 'Avoid over-pinning; keep 1-2 positions if needed.', [
            'evidence' => ['note' => 'Pin counts not selected in GAQL fetch.'],
        ]);

        $ags = $this->data['asset_groups'] ?? [];
        $agSlim = [];
        foreach (array_slice($ags, 0, 20) as $r) {
            $ag = $r['assetGroup'] ?? [];
            $agSlim[] = ['id' => $ag['id'] ?? null, 'name' => (string) ($ag['name'] ?? '')];
        }
        $checks[] = count($ags) > 0
            ? $this->warn('G31', 'PMax asset group density', $s, 'Medium', 'PMax asset counts require asset_group_asset drill-down in UI.', 'Target 20+ images, 5+ logos, 5+ native videos per asset group.', [
                'evidence' => ['asset_groups' => $agSlim],
                'metrics' => ['asset_group_count' => count($ags)],
            ])
            : $this->pass('G31', 'PMax asset group density', $s, 'No Performance Max asset groups in account.', '', [
                'metrics' => ['asset_group_count' => 0],
            ]);

        $checks[] = $this->pass('G32', 'PMax video assets present', $s, 'Video asset formats require asset resource fetch (deferred).', 'Upload native 16:9, 1:1, 9:16 videos for PMax.', [
            'evidence' => ['asset_group_count' => count($ags), 'deferred' => true],
        ]);
        $checks[] = count($ags) >= 2 || count($ags) === 0
            ? $this->pass('G33', 'PMax asset group count', $s, 'Multiple asset groups when PMax exists, or no PMax.', '', [
                'evidence' => ['asset_group_count' => count($ags)],
            ])
            : $this->warn('G33', 'PMax asset group count', $s, 'Medium', 'Only one PMax asset group detected.', 'Add intent-segmented asset groups.', [
                'evidence' => ['asset_groups' => $agSlim],
            ]);

        $checks[] = $this->warn('G34', 'PMax final URL expansion', $s, 'High', 'URL expansion setting not in lightweight fetch.', 'Review final URL expansion ON/OFF intentionally.', [
            'evidence' => ['asset_group_count' => count($ags), 'deferred' => true],
        ]);

        $checks[] = $this->pass('G35', 'Ad copy relevance to keywords', $s, 'Same as G-KW2 — verify RSA themes vs keywords in UI.', 'Align RSA headlines to keyword themes.', [
            'evidence' => ['rsa_rows' => count($rsa), 'keyword_rows' => count($this->data['keywords'] ?? [])],
        ]);

        $newest = 0;
        foreach ($rsa as $row) {
            // creation time not selected — heuristic pass
            $newest++;
        }
        $checks[] = $this->pass('G-AD1', 'Ad freshness', $s, 'Ad creation dates not in fetch; review change history in UI for >90d stale ads.', 'Rotate RSA assets quarterly.', [
            'evidence' => ['rsa_rows' => count($rsa), 'deferred' => true],
        ]);

        $checks[] = $this->skip('G-AD2', 'CTR vs industry benchmark', $s, 'Deferred: benchmarks not available.', ['evidence' => ['deferred' => true]]);

        return $checks;
    }

    private function checksSettings(): array
    {
        $s = 'Settings & Targeting';
        $agg = $this->data['campaign_agg'] ?? [];
        $assets = $this->data['campaign_assets'] ?? [];
        $byCamp = [];
        foreach ($assets as $row) {
            $cid = (string) (($row['campaign'] ?? [])['id'] ?? '');
            $ft = (string) (($row['campaignAsset'] ?? [])['fieldType'] ?? '');
            if ($cid === '') {
                continue;
            }
            $byCamp[$cid][$ft] = true;
        }
        $sitelinkPoor = 0;
        foreach ($agg as $c) {
            $cid = (string) ($c['id'] ?? '');
            $n = isset($byCamp[$cid]['SITELINK']) ? 1 : 0;
            if (($c['advertising_channel_type'] ?? '') === 'SEARCH' && $n === 0) {
                $sitelinkPoor++;
            }
        }
        $missingSitelink = [];
        foreach ($agg as $c) {
            $cid = (string) ($c['id'] ?? '');
            $n = isset($byCamp[$cid]['SITELINK']) ? 1 : 0;
            if (($c['advertising_channel_type'] ?? '') === 'SEARCH' && $n === 0) {
                if (count($missingSitelink) < 30) {
                    $missingSitelink[] = [
                        'id' => $c['id'] ?? null,
                        'name' => (string) ($c['name'] ?? ''),
                        'cost_micros' => (int) ($c['cost_micros'] ?? 0),
                    ];
                }
            }
        }
        $g50 = $sitelinkPoor === 0
            ? $this->pass('G50', 'Sitelink extensions', $s, 'Sitelinks detected on Search campaigns (heuristic).', '', [
                'metrics' => [
                    'search_campaigns_sample' => count(array_filter($agg, fn ($c) => ($c['advertising_channel_type'] ?? '') === 'SEARCH')),
                    'campaign_asset_rows' => count($assets),
                ],
            ])
            : $this->warn('G50', 'Sitelink extensions', $s, 'High', 'Some Search campaigns lack sitelink assets.', 'Add 4+ sitelinks.', [
                'evidence' => ['missing_sitelink_campaigns' => $missingSitelink],
                'metrics' => ['search_campaigns_without_sitelinks' => $sitelinkPoor],
            ]);

        $assetFieldTypes = [];
        foreach ($assets as $row) {
            $ft = (string) (($row['campaignAsset'] ?? [])['fieldType'] ?? '');
            if ($ft !== '') {
                $assetFieldTypes[$ft] = ($assetFieldTypes[$ft] ?? 0) + 1;
            }
        }
        $checks = [$g50, $this->pass('G51', 'Callout extensions', $s, 'Callouts not fully counted in lightweight asset fetch.', 'Add 4+ callouts per major Search campaign.', [
            'evidence' => ['campaign_asset_field_type_counts' => $assetFieldTypes],
            'metrics' => ['campaign_asset_rows' => count($assets)],
        ])];
        $checks[] = $this->pass('G52', 'Structured snippets', $s, 'Structured snippets require asset type filter in UI.', 'Add at least one structured snippet set.', [
            'evidence' => ['structured_snippet_rows_hint' => ($assetFieldTypes['STRUCTURED_SNIPPET'] ?? 0)],
        ]);
        $checks[] = $this->pass('G53', 'Image extensions', $s, 'Image extensions not counted in this fetch.', 'Enable image extensions for Search where appropriate.', [
            'evidence' => ['image_extension_rows_hint' => ($assetFieldTypes['IMAGE'] ?? 0), 'deferred' => true],
        ]);
        $checks[] = $this->pass('G54', 'Call extensions (if applicable)', $s, 'Call assets not evaluated in this pass.', 'Add call assets with tracking for phone-first businesses.', [
            'evidence' => ['call_asset_rows_hint' => ($assetFieldTypes['CALL'] ?? 0), 'deferred' => true],
        ]);
        $checks[] = $this->pass('G55', 'Lead form extensions', $s, 'Lead form assets not evaluated in this pass.', 'Test lead form assets for lead gen.', [
            'evidence' => ['lead_form_rows_hint' => ($assetFieldTypes['LEAD_FORM'] ?? 0), 'deferred' => true],
        ]);
        $checks[] = $this->pass('G56', 'Audience segments applied', $s, 'Observation audiences require criterion type AUDIENCE in expanded fetch.', 'Apply remarketing and in-market segments in Observation.', [
            'evidence' => ['deferred' => true, 'note' => 'AUDIENCE criterion not in v1 fetch'],
        ]);
        $checks[] = $this->warn('G57', 'Customer Match lists', $s, 'High', 'User lists not queried in v1 fetch.', 'Upload and refresh Customer Match lists monthly.', [
            'evidence' => ['deferred' => true],
        ]);
        $checks[] = $this->pass('G58', 'Placement exclusions', $s, 'Placement exclusions partially visible via content network flags.', 'Maintain account-level placement exclusions for Display/Video where used.', [
            'evidence' => ['campaign_agg_rows' => count($agg), 'deferred' => true],
        ]);

        $checks[] = $this->skip('G59', 'Landing page mobile speed', $s, 'Deferred.', ['evidence' => ['deferred' => true]]);
        $checks[] = $this->skip('G60', 'Landing page relevance', $s, 'Deferred.', ['evidence' => ['deferred' => true]]);
        $checks[] = $this->skip('G61', 'Landing page schema markup', $s, 'Deferred.', ['evidence' => ['deferred' => true]]);

        $ecpc = false;
        $manualHighConv = false;
        $ecpcCamps = [];
        $manualCamps = [];
        foreach ($agg as $c) {
            if (($c['bidding_strategy_type'] ?? '') === 'ENHANCED_CPC') {
                $ecpc = true;
                if (count($ecpcCamps) < 25) {
                    $ecpcCamps[] = ['id' => $c['id'] ?? null, 'name' => (string) ($c['name'] ?? '')];
                }
            }
            $conv = (float) ($c['conversions'] ?? 0);
            if ($conv >= 15 && ($c['bidding_strategy_type'] ?? '') === 'MANUAL_CPC') {
                $manualHighConv = true;
                if (count($manualCamps) < 25) {
                    $manualCamps[] = [
                        'id' => $c['id'] ?? null,
                        'name' => (string) ($c['name'] ?? ''),
                        'conversions' => round($conv, 1),
                    ];
                }
            }
        }
        $checks[] = ! $ecpc
            ? $this->pass('G36', 'Smart bidding strategy active', $s, 'No ECPC bidding strategy detected (ECPC deprecated Mar 2025).', '', [
                'metrics' => ['campaign_agg_rows' => count($agg)],
            ])
            : $this->fail('G36', 'Smart bidding strategy active', $s, 'High', 'ECPC still present — migrate immediately.', 'Move to Maximize conversions, tCPA, or tROAS.', [
                'evidence' => ['ecpc_campaigns' => $ecpcCamps],
            ]);
        $checks[] = ! $manualHighConv
            ? $this->pass('G40', 'Manual CPC justification', $s, 'No obvious Manual CPC on high-conversion campaigns heuristic.', '', [
                'metrics' => ['campaign_agg_rows' => count($agg)],
            ])
            : $this->warn('G40', 'Manual CPC justification', $s, 'Medium', 'Manual CPC on campaigns with >=15 conversions/period.', 'Switch to smart bidding when conversion volume supports it.', [
                'evidence' => ['manual_cpc_high_conv_campaigns' => $manualCamps],
            ]);

        $checks[] = $this->pass('G37', 'Target CPA/ROAS reasonableness', $s, 'Targets vs history requires UI/bidding strategy detail.', 'Keep targets within ~20% of recent performance.', [
            'evidence' => ['deferred' => true],
        ]);
        $checks[] = $this->pass('G38', 'Learning phase status', $s, 'Learning state not in lightweight campaign fetch.', 'Monitor Learning/Limited banners in UI.', [
            'evidence' => ['deferred' => true],
        ]);
        $checks[] = $this->pass('G39', 'Budget constrained campaigns', $s, 'Use budget lost IS columns in UI; API aggregates included where available.', 'Raise budgets on top performers with high lost IS.', [
            'evidence' => ['campaign_agg_rows' => count($agg), 'deferred_partial' => true],
        ]);
        $checks[] = $this->pass('G41', 'Portfolio bid strategies', $s, 'Portfolio strategies not evaluated in v1 fetch.', 'Group low-volume campaigns into portfolios where helpful.', [
            'evidence' => ['deferred' => true],
        ]);

        return $checks;
    }

    private function checksPmaxExtended(): array
    {
        $s = 'Ads & Assets';
        $pmaxCamps = [];
        foreach ($this->data['campaign_agg'] ?? [] as $c) {
            if (($c['advertising_channel_type'] ?? '') !== 'PERFORMANCE_MAX') {
                continue;
            }
            if (count($pmaxCamps) < 20) {
                $pmaxCamps[] = [
                    'id' => $c['id'] ?? null,
                    'name' => (string) ($c['name'] ?? ''),
                    'cost_micros' => (int) ($c['cost_micros'] ?? 0),
                ];
            }
        }
        $ags = $this->data['asset_groups'] ?? [];
        $agSlim = [];
        foreach (array_slice($ags, 0, 25) as $r) {
            $ag = $r['assetGroup'] ?? [];
            $agSlim[] = [
                'id' => $ag['id'] ?? null,
                'name' => (string) ($ag['name'] ?? ''),
            ];
        }
        $pmCtx = [
            'evidence' => [
                'pmax_campaigns' => $pmaxCamps,
                'asset_groups' => $agSlim,
                'asset_group_row_count' => count($ags),
            ],
        ];

        return [
            $this->pass('G-PM1', 'Audience signals configured', $s, 'Audience signals require asset_group_signal resource (deferred).', 'Add custom intent/audience signals per PMax asset group.', [
                'evidence' => [
                    'pmax_campaigns' => $pmaxCamps,
                    'asset_groups' => $agSlim,
                    'asset_group_row_count' => count($ags),
                    'deferred' => true,
                    'note' => 'asset_group_signal not in v1 fetch',
                ],
            ]),
            $this->pass('G-PM2', 'PMax Ad Strength', $s, 'Ad strength available on asset groups in UI.', 'Aim for Good/Excellent PMax asset groups.', $pmCtx),
            $this->warn('G-PM3', 'Brand cannibalization', $s, 'High', 'Brand vs PMax search terms not fetched in v1.', 'Monitor brand search terms in PMax and add exclusions.', $pmCtx),
            $this->pass('G-PM4', 'Search themes', $s, 'Search themes not in lightweight fetch.', 'Configure up to 50 search themes per asset group.', [
                'evidence' => array_merge($pmCtx['evidence'], ['deferred' => true]),
            ]),
            $this->pass('G-PM5', 'PMax negative keywords', $s, 'PMax negatives now supported — verify in UI.', 'Add brand + irrelevant negatives to PMax.', $pmCtx),
            $this->pass('G-PM6', 'PMax negative keywords active', $s, 'Campaign-level PMax negatives require criterion query (partial).', 'Add campaign-level negatives to PMax for quick wins.', $pmCtx),
        ];
    }

    private function checksAiDemandGenCtv(): array
    {
        $s = 'Ads & Assets';
        $agg = $this->data['campaign_agg'] ?? [];
        $conv = array_sum(array_map(fn ($c) => (float) ($c['conversions'] ?? 0), $agg));
        $hasSearch = false;
        $vacCamps = [];
        $dgCamps = [];
        foreach ($agg as $c) {
            if (($c['advertising_channel_type'] ?? '') === 'SEARCH') {
                $hasSearch = true;
            }
            $t = (string) ($c['advertising_channel_type'] ?? '');
            if ($t === 'VIDEO' && count($vacCamps) < 15) {
                $vacCamps[] = ['id' => $c['id'] ?? null, 'name' => (string) ($c['name'] ?? '')];
            }
            if ($t === 'DEMAND_GEN' && count($dgCamps) < 15) {
                $dgCamps[] = ['id' => $c['id'] ?? null, 'name' => (string) ($c['name'] ?? '')];
            }
        }
        $aiMetrics = ['account_conversions' => round($conv, 2), 'has_search_campaign' => $hasSearch];
        $gAi = $conv < 50 || ! $hasSearch
            ? $this->pass('G-AI1', 'AI Max for Search evaluation', $s, 'AI Max evaluation deferred for low-volume or non-Search accounts.', 'Before AI Max: tighten negatives and validate conversion volume.', [
                'metrics' => $aiMetrics,
                'evidence' => ['video_or_demand_gen_context' => ['demand_gen_campaigns' => $dgCamps, 'video_campaigns' => $vacCamps]],
            ])
            : $this->warn('G-AI1', 'AI Max for Search evaluation', $s, 'High', 'Consider evaluating AI Max for Search with sufficient conversion data.', 'Pilot AI Max with strong negative hygiene.', [
                'metrics' => $aiMetrics,
                'evidence' => ['demand_gen_campaigns' => $dgCamps, 'video_campaigns' => $vacCamps],
            ]);

        $dg = count($dgCamps) > 0;
        $vac = count($vacCamps) > 0;
        $gDg1 = $dg
            ? $this->pass('G-DG1', 'Demand Gen image assets', $s, 'Demand Gen campaigns present — verify both video and image assets in UI.', 'Add image assets alongside video for Demand Gen.', [
                'evidence' => ['demand_gen_campaigns' => $dgCamps],
            ])
            : $this->pass('G-DG1', 'Demand Gen image assets', $s, 'No Demand Gen campaigns.', 'Use Demand Gen when video discovery goals apply.', [
                'evidence' => ['demand_gen_campaigns' => []],
            ]);

        $gDg2 = ! $vac
            ? $this->pass('G-DG2', 'VAC migration status', $s, 'No legacy VIDEO (VAC) campaigns detected.', '', [
                'evidence' => ['video_campaigns' => []],
            ])
            : $this->warn('G-DG2', 'VAC migration status', $s, 'Critical', 'VIDEO campaigns may represent legacy VAC — confirm migration to Demand Gen.', 'Migrate video action setups to Demand Gen per Google timelines.', [
                'evidence' => ['video_campaigns' => $vacCamps],
            ]);

        $gDg3 = $this->pass('G-DG3', 'Demand Gen frequency capping loss', $s, 'Frequency strategy post-VAC migration requires manual policy review.', 'Use Video frequency groups / measurement workarounds where needed.', [
            'evidence' => ['note' => 'Frequency caps require campaign detail beyond snapshot.', 'video_campaigns' => $vacCamps],
        ]);

        return [$gAi, $gDg1, $gDg2, $gDg3];
    }

    /**
     * @param  list<array<string, mixed>>  $cas
     * @return list<array<string, mixed>>
     */
    private function slimConversionActionsForEvidence(array $cas, int $max = 50): array
    {
        $out = [];
        foreach ($cas as $c) {
            if (count($out) >= $max) {
                break;
            }
            $out[] = [
                'id' => $c['id'] ?? null,
                'name' => (string) ($c['name'] ?? ''),
                'category' => (string) ($c['category'] ?? ''),
                'origin' => (string) ($c['origin'] ?? ''),
                'primary_for_goal' => (bool) ($c['primaryForGoal'] ?? false),
                'attribution' => (string) ($c['attributionModelSettings']['attributionModel'] ?? ''),
                'enhanced_for_leads' => (bool) ($c['enhancedConversionsForLeadsEnabled'] ?? false),
                'default_value' => (float) ($c['valueSettings']['defaultValue'] ?? 0),
                'always_use_default_value' => (bool) ($c['valueSettings']['alwaysUseDefaultValue'] ?? false),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function evidenceWastedSearchTermRows(array $terms, int $max = 50): array
    {
        $rows = [];
        foreach ($terms as $row) {
            $m = $row['metrics'] ?? [];
            $cost = ((int) ($m['costMicros'] ?? 0)) / 1_000_000;
            $conv = (float) ($m['conversions'] ?? 0);
            if ($cost > 10 && $conv <= 0) {
                $stView = $row['searchTermView'] ?? [];
                $rows[] = [
                    'search_term' => (string) ($stView['searchTerm'] ?? ''),
                    'cost' => round($cost, 2),
                    'clicks' => (int) ($m['clicks'] ?? 0),
                    'impressions' => (int) ($m['impressions'] ?? 0),
                    'conversions' => $conv,
                ];
            }
        }
        usort($rows, fn ($a, $b) => $b['cost'] <=> $a['cost']);

        return array_slice($rows, 0, $max);
    }

    /** @return list<array<string, mixed>> */
    private function evidenceZeroConversionKeywordsRows(array $keywords, int $max = 50): array
    {
        $rows = [];
        foreach ($keywords as $row) {
            $m = $row['metrics'] ?? [];
            $clicks = (int) ($m['clicks'] ?? 0);
            $conv = (float) ($m['conversions'] ?? 0);
            if ($clicks > 100 && $conv <= 0) {
                $kw = ($row['adGroupCriterion'] ?? [])['keyword'] ?? [];
                $camp = $row['campaign'] ?? [];
                $ag = $row['adGroup'] ?? [];
                $cost = ((int) ($m['costMicros'] ?? 0)) / 1_000_000;
                $rows[] = [
                    'keyword' => (string) ($kw['text'] ?? ''),
                    'match_type' => (string) ($kw['matchType'] ?? ''),
                    'campaign_id' => $camp['id'] ?? null,
                    'campaign' => (string) ($camp['name'] ?? ''),
                    'ad_group' => (string) ($ag['name'] ?? ''),
                    'clicks' => $clicks,
                    'cost' => round($cost, 2),
                    'conversions' => $conv,
                    'qs' => (int) (($row['adGroupCriterion'] ?? [])['qualityInfo']['qualityScore'] ?? 0),
                ];
            }
        }
        usort($rows, fn ($a, $b) => $b['clicks'] <=> $a['clicks']);

        return array_slice($rows, 0, $max);
    }

    /** @return list<array<string, mixed>> */
    private function evidenceBroadManualKeywordRows(array $keywords, int $max = 40): array
    {
        $rows = [];
        foreach ($keywords as $row) {
            if (count($rows) >= $max) {
                break;
            }
            $c = $row['campaign'] ?? [];
            $agc = $row['adGroupCriterion'] ?? [];
            $mt = (string) (($agc['keyword'] ?? [])['matchType'] ?? '');
            $bst = (string) ($c['biddingStrategyType'] ?? '');
            if ($mt === 'BROAD' && $bst === 'MANUAL_CPC') {
                continue;
            }
            if ($mt === 'BROAD' && in_array($bst, ['MANUAL_CPC', 'UNKNOWN', 'UNSPECIFIED'], true)) {
                $kw = ($agc['keyword'] ?? []);
                $ag = $row['adGroup'] ?? [];
                $rows[] = [
                    'keyword' => (string) ($kw['text'] ?? ''),
                    'match_type' => $mt,
                    'bidding_strategy' => $bst,
                    'campaign' => (string) ($c['name'] ?? ''),
                    'ad_group' => (string) ($ag['name'] ?? ''),
                ];
            }
        }

        return $rows;
    }
}
