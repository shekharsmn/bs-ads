<?php

namespace App\Services;

class MetaCreativeService
{
    private const B2B_PAIN_WORDS = [
        'save time', 'reduce cost', 'increase revenue', 'scale', 'automate',
        'efficiency', 'productivity', 'roi', 'results', 'enterprise',
        'teams', 'workflow', 'integrate', 'streamline', 'grow', 'revenue',
        'pipeline', 'leads', 'demo', 'free trial', 'get started', 'book a call',
        'case study', 'trusted by', 'customers', 'clients',
    ];

    private const WEAK_WORDS = [
        'click here', 'learn more about us', 'we are', 'our company',
        'we offer', 'we provide', 'best in class', 'world-class',
        'cutting-edge', 'innovative solution', 'synergy',
    ];

    private function videoStat(array $ins, string $key): float
    {
        foreach ($ins[$key] ?? [] as $a) {
            return (float)($a['value'] ?? 0);
        }
        return 0;
    }

    private function actions(array $ins, string $type): float
    {
        foreach ($ins['actions'] ?? [] as $a) {
            if (($a['action_type'] ?? '') === $type) return (float)($a['value'] ?? 0);
        }
        return 0;
    }

    private function totalLeads(array $ins): float
    {
        return $this->actions($ins, 'lead')
            + $this->actions($ins, 'offsite_conversion.fb_pixel_lead')
            + $this->actions($ins, 'onsite_conversion.lead_grouped');
    }

    private function hookRate(array $ins): float
    {
        // 3-second plays = actions[video_view], NOT video_play_actions (which counts all plays)
        $plays3s = $this->actions($ins, 'video_view');
        $imp = (float)($ins['impressions'] ?? 0);
        return $imp > 0 ? $plays3s / $imp * 100 : 0;
    }

    private function thruplayRate(array $ins): float
    {
        $thruplays = $this->videoStat($ins, 'video_thruplay_watched_actions');
        $imp = (float)($ins['impressions'] ?? 0);
        return $imp > 0 ? $thruplays / $imp * 100 : 0;
    }

    private function avgWatchTime(array $ins): float
    {
        return $this->videoStat($ins, 'video_avg_time_watched_actions');
    }

    private function detectFormat(array $creative, array $videos): array
    {
        $objType = strtoupper($creative['object_type'] ?? '');
        $spec = $creative['object_story_spec'] ?? [];
        $feed = $creative['asset_feed_spec'] ?? [];

        if ($objType === 'VIDEO' || isset($spec['video_data'])) {
            $vidId = $spec['video_data']['video_id'] ?? null;
            $vidInfo = $vidId ? ($videos[$vidId] ?? []) : [];
            return ['VIDEO', $vidInfo];
        }
        if (isset($spec['template_data'])) return ['CAROUSEL', []];
        if ($feed) {
            if (!empty($feed['videos'])) return ['DYNAMIC_VIDEO', []];
            if (!empty($feed['images'])) return ['DYNAMIC_IMAGE', []];
            return ['DYNAMIC', []];
        }
        if (!empty($spec['link_data']['child_attachments'])) return ['CAROUSEL', []];
        return ['STATIC_IMAGE', []];
    }

    private function extractCopy(array $creative): array
    {
        $copy = ['primary_text' => null, 'headline' => null, 'description' => null, 'cta' => null, 'link_url' => null];
        $spec = $creative['object_story_spec'] ?? [];
        $linkData = $spec['link_data'] ?? [];
        if ($linkData) {
            $copy['primary_text'] = $linkData['message'] ?? $creative['body'] ?? null;
            $copy['headline'] = $linkData['name'] ?? $creative['title'] ?? null;
            $copy['description'] = $linkData['description'] ?? null;
            $copy['link_url'] = $linkData['link'] ?? null;
            $copy['cta'] = $linkData['call_to_action']['type'] ?? null;
        }
        $videoData = $spec['video_data'] ?? [];
        if ($videoData) {
            $copy['primary_text'] = $videoData['message'] ?? $copy['primary_text'];
            $copy['headline'] = $videoData['title'] ?? $copy['headline'];
            $copy['cta'] = $videoData['call_to_action']['type'] ?? $copy['cta'];
            $copy['link_url'] = $videoData['call_to_action']['value']['link'] ?? $copy['link_url'];
        }
        $feed = $creative['asset_feed_spec'] ?? [];
        if ($feed) {
            $bodies = $feed['bodies'] ?? [];
            $titles = $feed['titles'] ?? [];
            $ctas = $feed['call_to_action_types'] ?? [];
            $urls = $feed['link_urls'] ?? [];
            if ($bodies) $copy['primary_text'] = $bodies[0]['text'] ?? $copy['primary_text'];
            if ($titles) $copy['headline'] = $titles[0]['text'] ?? $copy['headline'];
            if ($ctas) $copy['cta'] = $ctas[0] ?? $copy['cta'];
            if ($urls) $copy['link_url'] = $urls[0]['website_url'] ?? $copy['link_url'];
        }
        if (!$copy['primary_text']) $copy['primary_text'] = $creative['body'] ?? null;
        if (!$copy['headline']) $copy['headline'] = $creative['title'] ?? null;
        return $copy;
    }

    private function analyzeCopy(array $copy, array $ins): array
    {
        $issues = [];
        $suggestions = [];
        $score = 100;
        $primary = trim($copy['primary_text'] ?? '');
        $headline = trim($copy['headline'] ?? '');
        $cta = $copy['cta'] ?? '';
        $combined = strtolower($primary . ' ' . $headline . ' ' . ($copy['description'] ?? ''));
        $ctr = (float)($ins['ctr'] ?? 0);
        $spend = (float)($ins['spend'] ?? 0);
        $leads = $this->totalLeads($ins);
        $imp = (float)($ins['impressions'] ?? 0);

        if (!$primary) { $issues[] = 'No primary text'; $suggestions[] = "Write a primary text opening with a pain point or bold claim."; $score -= 25; }
        elseif (strlen($primary) < 30) { $issues[] = "Primary text too short (" . strlen($primary) . " chars)"; $suggestions[] = "Expand primary text — '{$primary}' is too thin for B2B."; $score -= 10; }
        elseif (strlen($primary) > 500) { $issues[] = "Primary text very long (" . strlen($primary) . " chars)"; $suggestions[] = "Move your strongest hook to the first 125 chars. Opening: '" . substr($primary, 0, 125) . "...'"; $score -= 5; }

        if (!$headline) { $issues[] = 'No headline set'; $suggestions[] = "Add a headline. B2B formula: '[Outcome] for [ICP]'. E.g. 'Cut Reporting Time by 70%'."; $score -= 20; }
        elseif (strlen($headline) > 40) { $issues[] = "Headline too long (" . strlen($headline) . " chars)"; $suggestions[] = "Shorten '{$headline}' to under 27 chars. Keep only the core outcome."; $score -= 10; }

        $painHits = array_filter(self::B2B_PAIN_WORDS, fn($w) => str_contains($combined, $w));
        $weakHits = array_filter(self::WEAK_WORDS, fn($w) => str_contains($combined, $w));

        if (!$painHits) { $issues[] = 'No B2B outcome or pain language — copy reads as generic'; $suggestions[] = "Rewrite to name a specific outcome: 'Close Deals Faster', 'Eliminate Manual Reporting'."; $score -= 15; }
        if ($weakHits) { $issues[] = 'Corporate/weak language: ' . implode(', ', $weakHits); $score -= 10; }

        if (!$cta) { $issues[] = 'No CTA button configured'; $suggestions[] = "Add CTA: 'Learn More' for cold, 'Book a Demo'/'Get a Quote' for warm audiences."; $score -= 15; }

        $socialTerms = ['trusted by', 'customers', 'rated', 'reviews', 'case study', 'g2', '%', 'faster', 'saved'];
        if (!array_filter($socialTerms, fn($t) => str_contains($combined, $t))) {
            $suggestions[] = "Add social proof: '[N]+ companies use [Brand]', '★4.8 on G2', or a customer result.";
            $score -= 5;
        }

        if (!preg_match('/\d/', $combined)) { $suggestions[] = "Add a specific number: 'Save 8 hours/week', '3x more pipeline', '47% lower CAC'."; $score -= 5; }

        if ($imp > 500 && $ctr < 0.5 && $primary) {
            $suggestions[] = "CTR {$ctr}% is low. Test: 'Is your team still doing [pain] manually?' Opening: '" . substr($primary, 0, 80) . "...'";
        }
        if ($spend > 100 && $leads == 0) {
            $suggestions[] = "\${$spend} spent with 0 leads. Test: (1) new angle, (2) different CTA, (3) dedicated landing page.";
            $score -= 10;
        }

        $url = $copy['link_url'] ?? '';
        if ($url && preg_match('/\/(index|home)?\/?$/', $url)) {
            $suggestions[] = "Ad links to homepage ({$url}). Create a dedicated landing page matching the ad's offer.";
        }

        return ['score' => max(0, $score), 'issues' => $issues, 'suggestions' => $suggestions];
    }

    private function performanceRating(array $ins, string $format): array
    {
        $ctr = (float)($ins['ctr'] ?? 0);
        $cpc = (float)($ins['cpc'] ?? 0);
        $freq = (float)($ins['frequency'] ?? 0);
        $spend = (float)($ins['spend'] ?? 0);
        $leads = $this->totalLeads($ins);
        $imp = (float)($ins['impressions'] ?? 0);
        $ratings = [];
        $flags = [];

        if ($imp > 500) {
            $benchmark = in_array($format, ['VIDEO', 'DYNAMIC_VIDEO']) ? 0.5 : 0.5;
            if ($ctr < $benchmark) $flags[] = "CTR {$ctr}% is below B2B benchmark ({$benchmark}-1.5%)";
        }
        if ($freq > 4.0) $flags[] = "Frequency {$freq} — severe creative fatigue, audience oversaturated";
        elseif ($freq > 2.5) $flags[] = "Frequency {$freq} — approaching fatigue, prepare new creative";

        if ($spend > 20 && $leads == 0) $flags[] = "\${$spend} spent with 0 leads — not converting";
        elseif ($leads > 0) {
            $cpl = $spend / $leads;
            $ratings['cpl'] = '$' . number_format($cpl, 0);
            if ($cpl > 200) $flags[] = "CPL \${$cpl} is high for B2B Meta (benchmark \$80-\$150)";
        }

        if (in_array($format, ['VIDEO', 'DYNAMIC_VIDEO'])) {
            $hookRate = $this->hookRate($ins);
            $thruplayRate = $this->thruplayRate($ins);
            $avgWatch = $this->avgWatchTime($ins);
            $ratings['hook_rate'] = number_format($hookRate, 1) . '%';
            $ratings['thruplay_rate'] = number_format($thruplayRate, 1) . '%';
            $ratings['avg_watch_time'] = number_format($avgWatch, 0) . 's';

            if ($imp > 200) {
                if ($hookRate < 15) $flags[] = "Hook rate {$hookRate}% is low (benchmark 25%+) — open with motion or bold statement.";
                elseif ($hookRate < 25) $flags[] = "Hook rate {$hookRate}% is below 25% benchmark — tighten first 3 seconds.";
                if ($thruplayRate < 5) $flags[] = "ThruPlay rate {$thruplayRate}% is low (benchmark 15%+) — shorten video or move CTA earlier.";
            }
        }

        return [$ratings, $flags];
    }

    public function runAudit(array $data): array
    {
        $ads = $data['ads'] ?? [];
        $creatives = $data['creatives'] ?? [];
        $videos = $data['videos'] ?? [];
        $adInsightsMap = $data['adInsightsMap'] ?? [];

        $adToCreative = [];
        foreach ($creatives as $c) {
            if ($adId = $c['_ad_id'] ?? null) $adToCreative[$adId] = $c;
        }

        $perAd = [];
        $formatCounts = [];
        $formatSpend = [];
        $formatLeads = [];
        $ctaDist = [];
        $issuesSummary = [];

        foreach ($ads as $ad) {
            $adId = $ad['id'] ?? null;
            $adName = $ad['name'] ?? 'Unnamed Ad';
            $ins = $adInsightsMap[$adId] ?? [];
            $creative = $adToCreative[$adId] ?? null;

            if (!$creative) {
                $perAd[] = ['ad_id' => $adId, 'ad_name' => $adName, 'format' => 'UNKNOWN', 'spend' => (float)($ins['spend'] ?? 0), 'copy' => [], 'copy_analysis' => ['score' => 0, 'issues' => ['Creative data not available'], 'suggestions' => []], 'perf_ratings' => [], 'perf_flags' => [], 'video_info' => [], 'impressions' => 0, 'ctr' => 0, 'cpc' => 0, 'frequency' => 0, 'leads' => 0];
                continue;
            }

            [$format, $vidInfo] = $this->detectFormat($creative, $videos);
            $copy = $this->extractCopy($creative);
            $copyAnalysis = $this->analyzeCopy($copy, $ins);
            [$perfRatings, $perfFlags] = $this->performanceRating($ins, $format);

            $spend = (float)($ins['spend'] ?? 0);
            $leads = $this->totalLeads($ins);
            $formatCounts[$format] = ($formatCounts[$format] ?? 0) + 1;
            $formatSpend[$format] = ($formatSpend[$format] ?? 0) + $spend;
            $formatLeads[$format] = ($formatLeads[$format] ?? 0) + $leads;
            if ($cta = $copy['cta']) $ctaDist[$cta] = ($ctaDist[$cta] ?? 0) + 1;
            foreach ($copyAnalysis['issues'] as $issue) $issuesSummary[$issue] = ($issuesSummary[$issue] ?? 0) + 1;

            $videoMetrics = null;
            if (in_array($format, ['VIDEO', 'DYNAMIC_VIDEO'])) {
                $videoMetrics = [
                    'hook_rate' => $this->hookRate($ins),
                    'thruplay_rate' => $this->thruplayRate($ins),
                    'avg_watch_time' => $this->avgWatchTime($ins),
                    'duration' => (float)($vidInfo['length'] ?? 0),
                ];
            }

            $perAd[] = [
                'ad_id' => $adId,
                'ad_name' => $adName,
                'format' => $format,
                'spend' => $spend,
                'impressions' => (float)($ins['impressions'] ?? 0),
                'ctr' => (float)($ins['ctr'] ?? 0),
                'cpc' => (float)($ins['cpc'] ?? 0),
                'frequency' => (float)($ins['frequency'] ?? 0),
                'leads' => $leads,
                'copy' => $copy,
                'copy_analysis' => $copyAnalysis,
                'perf_ratings' => $perfRatings,
                'perf_flags' => $perfFlags,
                'video_info' => $vidInfo,
                'video_metrics' => $videoMetrics,
            ];
        }

        usort($perAd, fn($a, $b) => $b['spend'] <=> $a['spend']);

        arsort($issuesSummary);

        $summary = [
            'total_ads' => count($ads),
            'format_counts' => $formatCounts,
            'format_spend' => $formatSpend,
            'format_leads' => $formatLeads,
            'cta_distribution' => $ctaDist,
            'common_issues' => $issuesSummary,
        ];

        return [$perAd, $summary];
    }
}
