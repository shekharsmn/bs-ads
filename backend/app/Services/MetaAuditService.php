<?php

namespace App\Services;

class MetaAuditService
{
    private const SECTION_WEIGHTS = [
        'Pixel/CAPI' => 0.30,
        'Creative'   => 0.30,
        'Structure'  => 0.20,
        'Audience'   => 0.20,
    ];

    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    private function actions(array $ins, string $type): float
    {
        foreach ($ins['actions'] ?? [] as $a) {
            if (($a['action_type'] ?? '') === $type) return (float)($a['value'] ?? 0);
        }
        return 0;
    }

    private function videoStat(array $ins, string $key): float
    {
        foreach ($ins[$key] ?? [] as $a) {
            return (float)($a['value'] ?? 0);
        }
        return 0;
    }

    public function runAllChecks(): array
    {
        return [
            $this->checkM01(), $this->checkM02(), $this->checkM03(),
            $this->checkM04(), $this->checkM05(), $this->checkM06(),
            $this->checkM07(), $this->checkM08(), $this->checkM09(),
            $this->checkM10(), $this->checkM11(), $this->checkM12(),
            $this->checkM13(), $this->checkM14(), $this->checkM15(),
            $this->checkM16(), $this->checkM17(), $this->checkM18(),
            $this->checkM19(), $this->checkM20(), $this->checkM21(),
            $this->checkM22(),
        ];
    }

    public function calculateScore(array $results): array
    {
        $sectionScores = [];
        foreach (array_keys(self::SECTION_WEIGHTS) as $section) {
            $sectionResults = array_filter($results, fn($r) => $r['section'] === $section);
            if (!$sectionResults) { $sectionScores[$section] = 100; continue; }
            $avg = array_sum(array_column($sectionResults, 'score')) / count($sectionResults);
            $sectionScores[$section] = round($avg);
        }
        $total = 0;
        foreach (self::SECTION_WEIGHTS as $section => $weight) {
            $total += ($sectionScores[$section] ?? 100) * $weight;
        }
        return ['score' => (int)round($total), 'section_scores' => $sectionScores, 'weights' => self::SECTION_WEIGHTS];
    }

    public static function grade(int $score): string
    {
        return match(true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 60 => 'C',
            $score >= 40 => 'D',
            default      => 'F',
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

    /** @param  array{evidence?: array|null, metrics?: array|null, impact_score?: int|null}  $opts */
    private function pass(string $id, string $name, string $section, string $finding, string $rec = '', array $opts = []): array
    {
        $base = ['id' => $id, 'name' => $name, 'section' => $section, 'status' => 'PASS', 'severity' => null, 'finding' => $finding, 'recommendation' => $rec, 'score' => 100];

        return $this->finalizeCheck($base, $opts);
    }

    /** @param  array{evidence?: array|null, metrics?: array|null, impact_score?: int|null}  $opts */
    private function warn(string $id, string $name, string $section, string $severity, string $finding, string $rec = '', array $opts = []): array
    {
        $score = match ($severity) {
            'Critical' => 20, 'High' => 40, 'Medium' => 60, 'Low' => 80, default => 70
        };
        $base = ['id' => $id, 'name' => $name, 'section' => $section, 'status' => 'WARN', 'severity' => $severity, 'finding' => $finding, 'recommendation' => $rec, 'score' => $score];

        return $this->finalizeCheck($base, $opts);
    }

    /** @param  array{evidence?: array|null, metrics?: array|null, impact_score?: int|null}  $opts */
    private function fail(string $id, string $name, string $section, string $severity, string $finding, string $rec = '', array $opts = []): array
    {
        $score = match ($severity) {
            'Critical' => 0, 'High' => 20, 'Medium' => 40, 'Low' => 60, default => 30
        };
        $base = ['id' => $id, 'name' => $name, 'section' => $section, 'status' => 'FAIL', 'severity' => $severity, 'finding' => $finding, 'recommendation' => $rec, 'score' => $score];

        return $this->finalizeCheck($base, $opts);
    }

    // --- Pixel/CAPI checks ---

    private function checkM01(): array
    {
        $pixels = $this->data['pixels'] ?? [];
        $pixEv = array_map(fn ($p) => [
            'id' => $p['id'] ?? null,
            'name' => (string) ($p['name'] ?? ''),
            'last_fired_time' => $p['last_fired_time'] ?? null,
        ], array_slice($pixels, 0, 15));
        if (! $pixels) {
            return $this->fail('M01', 'Meta Pixel installed', 'Pixel/CAPI', 'Critical', 'No Meta Pixel found.', 'Install the Meta Pixel on your website via Events Manager.', [
                'evidence' => ['pixels' => []],
            ]);
        }
        $recent = array_filter($pixels, fn ($p) => ! empty($p['last_fired_time']));
        if (! $recent) {
            return $this->warn('M01', 'Meta Pixel installed', 'Pixel/CAPI', 'High', 'Pixel found but has not fired recently.', 'Verify pixel is firing on key pages using Meta Pixel Helper.', [
                'evidence' => ['pixels' => $pixEv],
            ]);
        }

        return $this->pass('M01', 'Meta Pixel installed', 'Pixel/CAPI', count($pixels).' pixel(s) found and firing.', '', [
            'evidence' => ['pixels' => $pixEv],
        ]);
    }

    private function checkM02(): array
    {
        $stats = $this->data['pixelStats'] ?? [];
        $allEvents = [];
        foreach ($stats as $ps) {
            foreach ($ps['stats'] ?? [] as $st) {
                $en = $st['event_name'] ?? null;
                if ($en) {
                    $allEvents[] = (string) $en;
                }
            }
        }
        $uniqueEvents = array_values(array_unique($allEvents));
        $b2bEvents = ['Lead', 'CompleteRegistration', 'Contact', 'Purchase', 'ViewContent', 'SubmitApplication'];
        $found = array_values(array_intersect($b2bEvents, $uniqueEvents));
        $evBase = [
            'pixel_stats_rows' => count($stats),
            'unique_events_sample' => array_slice($uniqueEvents, 0, 40),
            'b2b_events_sought' => $b2bEvents,
        ];
        if (! $found) {
            return $this->fail('M02', 'Key conversion events tracked', 'Pixel/CAPI', 'Critical', 'No B2B conversion events detected. Events firing: ' . (implode(', ', array_slice($uniqueEvents, 0, 12)) ?: 'None'), 'Set up Lead or CompleteRegistration events on your forms/thank-you pages.', [
                'evidence' => array_merge($evBase, ['matched_b2b_events' => []]),
                'metrics' => ['unique_event_types' => count($uniqueEvents)],
            ]);
        }

        return $this->pass('M02', 'Key conversion events tracked', 'Pixel/CAPI', 'Events detected: ' . implode(', ', $found), '', [
            'evidence' => array_merge($evBase, ['matched_b2b_events' => $found]),
            'metrics' => ['unique_event_types' => count($uniqueEvents)],
        ]);
    }

    private function checkM03(): array
    {
        return $this->warn('M03', 'Conversions API (CAPI) setup', 'Pixel/CAPI', 'High', 'CAPI status cannot be verified via API. Requires manual check in Events Manager.', 'Set up CAPI to improve signal quality and iOS 14+ resilience.', [
            'evidence' => ['deferred' => true, 'note' => 'Verify CAPI + dedup in Events Manager'],
            'metrics' => ['pixel_count' => count($this->data['pixels'] ?? [])],
        ]);
    }

    private function checkM04(): array
    {
        return $this->warn('M04', 'Event Match Quality (EMQ)', 'Pixel/CAPI', 'Medium', 'EMQ score not available via API. Requires manual check.', 'Aim for EMQ score 6+. Pass email, phone, and name with events.', [
            'evidence' => ['deferred' => true],
        ]);
    }

    private function checkM05(): array
    {
        $conversions = $this->data['customConversions'] ?? [];
        $slim = array_map(fn ($c) => [
            'id' => $c['id'] ?? null,
            'name' => (string) ($c['name'] ?? ''),
        ], array_slice($conversions, 0, 25));
        if (! $conversions) {
            return $this->fail('M05', 'Custom conversions configured', 'Pixel/CAPI', 'High', 'No custom conversions found.', 'Create custom conversions for your key B2B events (lead form submissions, demo bookings).', [
                'evidence' => ['custom_conversions' => []],
            ]);
        }

        return $this->pass('M05', 'Custom conversions configured', 'Pixel/CAPI', count($conversions) . ' custom conversion(s) configured.', '', [
            'evidence' => ['custom_conversions' => $slim],
            'metrics' => ['custom_conversion_count' => count($conversions)],
        ]);
    }

    // --- Creative checks ---

    private function checkM06(): array
    {
        $ads = $this->data['ads'] ?? [];
        $adInsightsMap = $this->data['adInsightsMap'] ?? [];
        $fatigued = [];
        foreach ($ads as $ad) {
            $ins = $adInsightsMap[$ad['id']] ?? [];
            $freq = (float) ($ins['frequency'] ?? 0);
            if ($freq > 3.5 && count($fatigued) < 30) {
                $fatigued[] = [
                    'ad_id' => $ad['id'] ?? null,
                    'name' => (string) ($ad['name'] ?? 'Unknown'),
                    'frequency' => round($freq, 2),
                ];
            }
        }
        $fatCount = count($fatigued);
        if ($fatCount > 2) {
            return $this->fail('M06', 'Creative fatigue', 'Creative', 'High', $fatCount . ' ad(s) with severe fatigue (freq >3.5): ' . implode(', ', array_map(fn ($f) => $f['name'], array_slice($fatigued, 0, 3))), 'Pause fatigued ads and launch fresh creative variants.', [
                'evidence' => ['fatigued_ads' => $fatigued],
                'metrics' => ['fatigued_ad_count' => $fatCount, 'ads_evaluated' => count($ads)],
            ]);
        }
        if ($fatigued) {
            return $this->warn('M06', 'Creative fatigue', 'Creative', 'Medium', $fatCount . ' ad(s) approaching creative fatigue.', 'Prepare new creative. Frequency above 3.5 significantly reduces performance.', [
                'evidence' => ['fatigued_ads' => $fatigued],
                'metrics' => ['fatigued_ad_count' => $fatCount],
            ]);
        }

        return $this->pass('M06', 'Creative fatigue', 'Creative', 'All ads have acceptable frequency levels.', '', [
            'metrics' => ['ads_evaluated' => count($ads)],
        ]);
    }

    private function checkM07(): array
    {
        $creatives = $this->data['creatives'] ?? [];
        $formats = [];
        foreach ($creatives as $c) {
            $type = strtoupper($c['object_type'] ?? 'UNKNOWN');
            $formats[$type] = ($formats[$type] ?? 0) + 1;
        }
        $keys = array_keys($formats);
        if (count($formats) < 2) {
            return $this->warn('M07', 'Creative format diversity', 'Creative', 'Medium', 'Only ' . count($formats) . ' format type(s) detected: ' . implode(', ', $keys), 'Test at least 3 formats: video, static image, and carousel.', [
                'evidence' => ['format_counts' => $formats],
                'metrics' => ['creative_rows' => count($creatives)],
            ]);
        }

        return $this->pass('M07', 'Creative format diversity', 'Creative', 'Multiple creative formats in use: ' . implode(', ', $keys), '', [
            'evidence' => ['format_counts' => $formats],
            'metrics' => ['creative_rows' => count($creatives)],
        ]);
    }

    private function checkM08(): array
    {
        $ads = $this->data['ads'] ?? [];
        $adInsightsMap = $this->data['adInsightsMap'] ?? [];
        $lowCtr = 0;
        $total = 0;
        $lowSamples = [];
        foreach ($ads as $ad) {
            $ins = $adInsightsMap[$ad['id']] ?? [];
            $imp = (float) ($ins['impressions'] ?? 0);
            $ctr = (float) ($ins['ctr'] ?? 0);
            if ($imp > 500) {
                $total++;
                if ($ctr < 0.5) {
                    $lowCtr++;
                    if (count($lowSamples) < 25) {
                        $lowSamples[] = [
                            'ad_id' => $ad['id'] ?? null,
                            'name' => (string) ($ad['name'] ?? ''),
                            'ctr' => round($ctr, 3),
                            'impressions' => (int) round($imp),
                        ];
                    }
                }
            }
        }
        if ($total === 0) {
            return $this->pass('M08', 'Ad CTR performance', 'Creative', 'Insufficient impressions to evaluate CTR.', '', [
                'metrics' => ['ads_with_insights' => count($ads)],
            ]);
        }
        $pct = (int) round($lowCtr / $total * 100);
        $metrics = ['ads_meeting_impr_threshold' => $total, 'low_ctr_ads' => $lowCtr, 'low_ctr_pct' => $pct];
        if ($pct > 50) {
            return $this->fail('M08', 'Ad CTR performance', 'Creative', 'High', "{$pct}% of ads have CTR below 0.5% benchmark.", 'Rewrite ad copy hooks and test new creative angles.', [
                'evidence' => ['low_ctr_ads' => $lowSamples],
                'metrics' => $metrics,
            ]);
        }
        if ($pct > 20) {
            return $this->warn('M08', 'Ad CTR performance', 'Creative', 'Medium', "{$pct}% of ads have CTR below 0.5% benchmark.", 'Test new headlines and primary text for underperforming ads.', [
                'evidence' => ['low_ctr_ads' => $lowSamples],
                'metrics' => $metrics,
            ]);
        }

        return $this->pass('M08', 'Ad CTR performance', 'Creative', 'Most ads meeting CTR benchmarks.', '', [
            'evidence' => ['low_ctr_ads' => []],
            'metrics' => $metrics,
        ]);
    }

    private function checkM09(): array
    {
        return $this->warn('M09', 'CTA variety', 'Creative', 'Low', 'CTA mix not available via this API fetch (manual check recommended).', 'Test multiple CTAs: Learn More (cold), Book a Demo (warm), Get Quote (hot).', [
            'evidence' => ['deferred' => true, 'ads_sample_size' => count($this->data['ads'] ?? [])],
        ]);
    }

    // --- Structure checks ---

    private function checkM10(): array
    {
        $campaigns = $this->data['campaigns'] ?? [];
        if (! $campaigns) {
            return $this->fail('M10', 'Active campaigns exist', 'Structure', 'Critical', 'No active campaigns found.', 'Create and activate campaigns to start advertising.', [
                'evidence' => ['campaigns' => []],
            ]);
        }
        $slim = array_map(fn ($c) => ['id' => $c['id'] ?? null, 'name' => (string) ($c['name'] ?? ''), 'status' => $c['status'] ?? null], array_slice($campaigns, 0, 30));

        return $this->pass('M10', 'Active campaigns exist', 'Structure', count($campaigns) . ' active campaign(s) found.', '', [
            'evidence' => ['campaigns' => $slim],
            'metrics' => ['campaign_count' => count($campaigns)],
        ]);
    }

    private function checkM11(): array
    {
        $campaigns = $this->data['campaigns'] ?? [];
        $cboCampaigns = array_filter($campaigns, fn ($c) => ! empty($c['daily_budget']) || ! empty($c['lifetime_budget']));
        $nonCboList = [];
        foreach ($campaigns as $c) {
            if (empty($c['daily_budget']) && empty($c['lifetime_budget']) && count($nonCboList) < 25) {
                $nonCboList[] = ['id' => $c['id'] ?? null, 'name' => (string) ($c['name'] ?? '')];
            }
        }
        $nonCbo = count($campaigns) - count($cboCampaigns);
        if ($nonCbo > 2) {
            return $this->warn('M11', 'Campaign Budget Optimization (CBO)', 'Structure', 'Medium', "{$nonCbo} campaign(s) using ad set level budgets instead of CBO.", 'Switch to CBO to let Meta optimize budget allocation across ad sets.', [
                'evidence' => ['campaigns_without_campaign_budget' => $nonCboList],
                'metrics' => ['non_cbo_campaign_count' => $nonCbo, 'campaign_count' => count($campaigns)],
            ]);
        }

        return $this->pass('M11', 'Campaign Budget Optimization (CBO)', 'Structure', 'Most campaigns using CBO.', '', [
            'metrics' => ['campaign_count' => count($campaigns), 'non_cbo_campaign_count' => $nonCbo],
        ]);
    }

    private function checkM12(): array
    {
        $adsets = $this->data['adsets'] ?? [];
        $adsBySet = [];
        foreach ($this->data['ads'] ?? [] as $ad) {
            $adsBySet[$ad['adset_id']][] = $ad;
        }
        $thin = [];
        foreach ($adsets as $as) {
            $count = count($adsBySet[$as['id']] ?? []);
            if ($count < 2 && count($thin) < 30) {
                $thin[] = [
                    'adset_id' => $as['id'] ?? null,
                    'name' => (string) ($as['name'] ?? 'Unknown'),
                    'active_ad_count' => $count,
                ];
            }
        }
        $thinCount = count($thin);
        if ($thinCount > 3) {
            return $this->warn('M12', 'Minimum ads per ad set', 'Structure', 'Medium', $thinCount . ' ad set(s) with fewer than 2 active ads.', 'Run at least 2-4 creative variants per ad set for Meta\'s algorithm to optimize.', [
                'evidence' => ['thin_ad_sets' => $thin],
                'metrics' => ['thin_ad_set_count' => $thinCount],
            ]);
        }
        if ($thin) {
            return $this->warn('M12', 'Minimum ads per ad set', 'Structure', 'Low', $thinCount . ' ad set(s) with fewer than 2 active ads.', 'Add more creative variants to thin ad sets.', [
                'evidence' => ['thin_ad_sets' => $thin],
            ]);
        }

        return $this->pass('M12', 'Minimum ads per ad set', 'Structure', 'All ad sets have sufficient ad variants.', '', [
            'metrics' => ['adset_count' => count($adsets)],
        ]);
    }

    private function checkM13(): array
    {
        $adsets = $this->data['adsets'] ?? [];
        $noBid = [];
        foreach ($adsets as $as) {
            if (empty($as['bid_strategy']) && count($noBid) < 25) {
                $noBid[] = ['id' => $as['id'] ?? null, 'name' => (string) ($as['name'] ?? '')];
            }
        }
        if (count($noBid) > 1) {
            return $this->warn('M13', 'Bid strategy set', 'Structure', 'Low', count($noBid) . ' ad set(s) with no explicit bid strategy.', 'Set bid strategy to Lowest Cost or Cost Cap depending on campaign goal.', [
                'evidence' => ['ad_sets_missing_bid_strategy' => array_values($noBid)],
            ]);
        }

        return $this->pass('M13', 'Bid strategy set', 'Structure', 'Bid strategies configured on ad sets.', '', [
            'metrics' => ['adset_count' => count($adsets), 'missing_bid_strategy' => count($noBid)],
        ]);
    }

    private function checkM14(): array
    {
        $campaigns = $this->data['campaigns'] ?? [];
        $objectives = array_values(array_unique(array_filter(array_column($campaigns, 'objective'))));
        $b2bObjectives = ['LEAD_GENERATION', 'CONVERSIONS', 'OUTCOME_LEADS', 'OUTCOME_SALES'];
        $good = array_values(array_intersect($b2bObjectives, $objectives));
        if (! $good) {
            return $this->warn('M14', 'Campaign objective alignment', 'Structure', 'Medium', 'No Lead or Conversion objective campaigns detected. Objectives: ' . implode(', ', $objectives), 'For B2B, use Lead Generation or Conversions objectives to drive measurable results.', [
                'evidence' => ['objectives_seen' => $objectives, 'b2b_objectives_sought' => $b2bObjectives],
            ]);
        }

        return $this->pass('M14', 'Campaign objective alignment', 'Structure', 'B2B-appropriate objectives in use: ' . implode(', ', $good), '', [
            'evidence' => ['matched_objectives' => $good, 'objectives_seen' => $objectives],
        ]);
    }

    // --- Audience checks ---

    private function checkM15(): array
    {
        $audiences = $this->data['audiences'] ?? [];
        $slimAll = array_map(fn ($a) => [
            'id' => $a['id'] ?? null,
            'name' => (string) ($a['name'] ?? ''),
            'subtype' => $a['subtype'] ?? null,
        ], array_slice($audiences, 0, 25));
        if (! $audiences) {
            return $this->fail('M15', 'Retargeting audiences', 'Audience', 'High', 'No website or engagement retargeting audiences found.', 'Create website visitor and video viewer retargeting audiences. Retargeting is 3-5x more efficient than cold prospecting.', [
                'evidence' => ['audiences' => []],
            ]);
        }
        $retargeting = array_values(array_filter($audiences, fn ($a) => in_array($a['subtype'] ?? '', ['WEBSITE', 'ENGAGEMENT', 'VIDEO', 'PAGE', 'LEAD_GENERATION'], true)));
        if (! $retargeting) {
            return $this->warn('M15', 'Retargeting audiences', 'Audience', 'High', 'Custom audiences exist but no retargeting-type audiences found.', 'Create website visitor retargeting (30d, 60d, 90d windows) and video viewer retargeting.', [
                'evidence' => ['audiences_sample' => $slimAll],
            ]);
        }
        $rtSlim = array_map(fn ($a) => ['id' => $a['id'] ?? null, 'name' => (string) ($a['name'] ?? ''), 'subtype' => $a['subtype'] ?? null], array_slice($retargeting, 0, 25));

        return $this->pass('M15', 'Retargeting audiences', 'Audience', count($retargeting) . ' retargeting audience(s) configured.', '', [
            'evidence' => ['retargeting_audiences' => $rtSlim],
            'metrics' => ['retargeting_count' => count($retargeting), 'audience_count' => count($audiences)],
        ]);
    }

    private function checkM16(): array
    {
        $audiences = $this->data['audiences'] ?? [];
        $lookalikes = array_values(array_filter($audiences, fn ($a) => ($a['subtype'] ?? '') === 'LOOKALIKE'));
        if (! $lookalikes) {
            return $this->warn('M16', 'Lookalike audiences', 'Audience', 'Medium', 'No Lookalike Audiences found.', 'Create 1% LAL from your customer list or lead list. LALs typically outperform interest targeting by 30-50%.', [
                'evidence' => ['lookalike_audiences' => []],
                'metrics' => ['audience_count' => count($audiences)],
            ]);
        }
        $slim = array_map(fn ($a) => ['id' => $a['id'] ?? null, 'name' => (string) ($a['name'] ?? '')], array_slice($lookalikes, 0, 20));

        return $this->pass('M16', 'Lookalike audiences', 'Audience', count($lookalikes) . ' Lookalike Audience(s) found.', '', [
            'evidence' => ['lookalike_audiences' => $slim],
            'metrics' => ['lookalike_count' => count($lookalikes)],
        ]);
    }

    private function checkM17(): array
    {
        $adsets = $this->data['adsets'] ?? [];
        $n = count($adsets);
        if ($n > 5) {
            return $this->warn('M17', 'Audience overlap risk', 'Audience', 'Medium', $n . ' active ad sets — audience overlap cannot be verified via API.', 'Use Audience Overlap tool in Ads Manager to identify and consolidate overlapping ad sets.', [
                'evidence' => ['ad_sets_sample' => array_map(fn ($as) => ['id' => $as['id'] ?? null, 'name' => (string) ($as['name'] ?? '')], array_slice($adsets, 0, 15))],
                'metrics' => ['adset_count' => $n],
            ]);
        }

        return $this->pass('M17', 'Audience overlap risk', 'Audience', 'Ad set count is manageable. Monitor overlap in Ads Manager.', '', [
            'metrics' => ['adset_count' => $n],
        ]);
    }

    private function checkM18(): array
    {
        return $this->warn('M18', 'Advantage+ Audiences', 'Audience', 'Low', 'Advantage+ audience usage cannot be fully verified via API.', 'Test Advantage+ Audiences on your top-spending ad sets. Meta reports 10-20% efficiency improvement on average.', [
            'evidence' => ['deferred' => true],
            'metrics' => ['adset_count' => count($this->data['adsets'] ?? [])],
        ]);
    }

    private function checkM19(): array
    {
        $adInsightsMap = $this->data['adInsightsMap'] ?? [];
        $totalSpend = array_sum(array_map(fn ($i) => (float) ($i['spend'] ?? 0), $adInsightsMap));
        $totalLeads = 0;
        foreach ($adInsightsMap as $ins) {
            foreach ($ins['actions'] ?? [] as $a) {
                if (in_array($a['action_type'], ['lead', 'offsite_conversion.fb_pixel_lead', 'onsite_conversion.lead_grouped'], true)) {
                    $totalLeads += (float) ($a['value'] ?? 0);
                }
            }
        }
        $metrics = [
            'total_spend' => round($totalSpend, 2),
            'total_leads' => round($totalLeads, 2),
            'ad_insight_rows' => count($adInsightsMap),
        ];
        if ($totalSpend > 500 && $totalLeads === 0) {
            return $this->fail('M19', 'Account-level lead volume', 'Structure', 'Critical', "\${$totalSpend} spent with 0 leads tracked. Conversion tracking may be broken.", 'Verify pixel lead events are firing and custom conversions are set up correctly.', [
                'evidence' => ['lead_actions' => []],
                'metrics' => $metrics,
            ]);
        }
        if ($totalLeads > 0) {
            $cpl = $totalSpend / $totalLeads;
            if ($cpl > 300) {
                return $this->warn('M19', 'Account-level lead volume', 'Structure', 'High', "CPL \${$cpl} is above B2B benchmark (\$80-\$150). Total leads: {$totalLeads}.", 'Pause underperforming ads and test new creative angles to reduce CPL.', [
                    'evidence' => ['implied_cpl' => round($cpl, 2)],
                    'metrics' => $metrics,
                ]);
            }
        }

        return $this->pass('M19', 'Account-level lead volume', 'Structure', "Total leads (30d): {$totalLeads} | Total spend: \${$totalSpend}", '', [
            'metrics' => $metrics,
        ]);
    }

    private function checkM20(): array
    {
        $adsets = $this->data['adsets'] ?? [];
        $noTarget = [];
        foreach ($adsets as $as) {
            if (empty($as['targeting']) && count($noTarget) < 25) {
                $noTarget[] = ['id' => $as['id'] ?? null, 'name' => (string) ($as['name'] ?? '')];
            }
        }
        if ($noTarget) {
            return $this->warn('M20', 'Audience targeting configured', 'Audience', 'Medium', count($noTarget) . ' ad set(s) with no visible targeting configuration.', 'Verify targeting is set — broad targeting can waste budget on irrelevant audiences.', [
                'evidence' => ['ad_sets_missing_targeting_payload' => $noTarget],
            ]);
        }

        return $this->pass('M20', 'Audience targeting configured', 'Audience', 'All ad sets have targeting configured.', '', [
            'metrics' => ['adset_count' => count($adsets)],
        ]);
    }

    private function checkM21(): array
    {
        $adsets = $this->data['adsets'] ?? [];
        $destinations = array_values(array_unique(array_filter(array_column($adsets, 'destination_type'))));
        if (in_array('WEBSITE', $destinations, true) || in_array('ON_AD', $destinations, true)) {
            return $this->pass('M21', 'Lead destination type', 'Structure', 'Ad sets configured with website or on-ad lead destinations.', '', [
                'evidence' => ['destination_types' => $destinations],
            ]);
        }

        return $this->warn('M21', 'Lead destination type', 'Structure', 'Low', 'Lead destination type unclear from available data.', 'Ensure each ad set has the correct destination: Website for pixel leads, On-ad for Instant Forms.', [
            'evidence' => ['destination_types' => $destinations],
        ]);
    }

    private function checkM22(): array
    {
        $campaigns = $this->data['campaigns'] ?? [];
        $special = [];
        foreach ($campaigns as $c) {
            $sac = $c['special_ad_categories'] ?? [];
            if (! empty($sac) && $sac !== ['NONE']) {
                $special[] = [
                    'id' => $c['id'] ?? null,
                    'name' => (string) ($c['name'] ?? ''),
                    'special_ad_categories' => $sac,
                ];
            }
        }
        if ($special) {
            return $this->warn('M22', 'Special ad category compliance', 'Structure', 'High', count($special) . ' campaign(s) with special ad categories declared.', 'Ensure targeting restrictions are applied correctly for Housing, Credit, Employment, or Political categories.', [
                'evidence' => ['campaigns' => array_slice($special, 0, 20)],
            ]);
        }

        return $this->pass('M22', 'Special ad category compliance', 'Structure', 'No special ad categories flagged.', '', [
            'metrics' => ['campaign_count' => count($campaigns)],
        ]);
    }
}
