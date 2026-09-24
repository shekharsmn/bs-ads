<?php

namespace App\Services\Audit;

/**
 * Static explainer copy for each Google audit check id (authoritative, not AI-generated).
 */
final class GoogleCheckMethodology
{
    /** @var list<string> */
    private const IDS = [
        'G01', 'G02', 'G03', 'G04', 'G05', 'G06', 'G07', 'G08', 'G09', 'G10', 'G11', 'G12', 'G13', 'G14', 'G15', 'G16', 'G17', 'G18', 'G19',
        'G20', 'G21', 'G22', 'G23', 'G24', 'G25', 'G26', 'G27', 'G28', 'G29', 'G30', 'G31', 'G32', 'G33', 'G34', 'G35', 'G36', 'G37', 'G38', 'G39', 'G40', 'G41',
        'G42', 'G43', 'G44', 'G45', 'G46', 'G47', 'G48', 'G49', 'G50', 'G51', 'G52', 'G53', 'G54', 'G55', 'G56', 'G57', 'G58', 'G59', 'G60', 'G61',
        'G-WS1', 'G-KW1', 'G-KW2', 'G-AD1', 'G-AD2', 'G-CT1', 'G-CT2', 'G-CT3', 'G-CTV1',
        'G-PM1', 'G-PM2', 'G-PM3', 'G-PM4', 'G-PM5', 'G-PM6', 'G-DG1', 'G-DG2', 'G-DG3', 'G-AI1',
    ];

    private const SECTION_FOCUS = [
        'Structure' => [
            'why_it_matters' => 'Clean structure makes budgets, bidding, and diagnostics trustworthy — messy accounts hide waste and slow learning.',
            'how_we_measure' => 'Heuristics on campaign_agg and keyword_view: naming diversity, ad group breadth, brand separation, channel mix, geo/network flags, and budgets.',
        ],
        'Wasted Spend' => [
            'why_it_matters' => 'Irrelevant queries and poor match/bidding pairings spend budget on traffic that will not convert.',
            'how_we_measure' => 'search_term_view spend patterns, shared negative lists, campaign–list links, broad+manual keyword pairs, and high-click zero-conv keywords.',
        ],
        'Keywords & QS' => [
            'why_it_matters' => 'Quality Score and keyword hygiene directly affect CPCs, impression share, and whether queries match your offers.',
            'how_we_measure' => 'Impression-weighted QS, QS histograms, zero-impression share, and keyword-level metrics from keyword_view in the audit window.',
        ],
        'Ads & Assets' => [
            'why_it_matters' => 'RSAs, extensions, and PMax assets determine ad relevance, eligible impressions, and whether automation has enough creative signal.',
            'how_we_measure' => 'ad_group_ad RSA rows, headline counts, ad strength, asset_groups, and channel-specific rules from the snapshot.',
        ],
        'Conversion Tracking' => [
            'why_it_matters' => 'Accurate conversion definitions power bidding, reporting, and experiment validity — double counting or weak goals mislead the whole stack.',
            'how_we_measure' => 'Enabled conversion_action resources: origins, primary-for-goal, attribution, values, enhanced conversions signals.',
        ],
        'Settings & Targeting' => [
            'why_it_matters' => 'Extensions, audiences, and bidding mode determine reach, trust, and whether deprecated modes silently cap performance.',
            'how_we_measure' => 'Campaign assets, ECPC/manual CPC flags on campaign_agg, and extension heuristics from the lightweight asset fetch.',
        ],
        'General' => [
            'why_it_matters' => 'This checklist item supports overall account health and measurement quality.',
            'how_we_measure' => 'See the Evidence and metrics payload saved with this check for the exact inputs used on this run.',
        ],
    ];

    /** @var array<string, string> check_id => section key matching SECTION_FOCUS */
    private const ID_SECTION = [
        'G01' => 'Structure', 'G02' => 'Structure', 'G03' => 'Structure', 'G04' => 'Structure', 'G05' => 'Structure', 'G06' => 'Structure',
        'G07' => 'Structure', 'G08' => 'Structure', 'G09' => 'Structure', 'G10' => 'Structure', 'G11' => 'Structure', 'G12' => 'Structure',
        'G13' => 'Wasted Spend', 'G14' => 'Wasted Spend', 'G15' => 'Wasted Spend', 'G16' => 'Wasted Spend', 'G17' => 'Wasted Spend', 'G18' => 'Wasted Spend', 'G19' => 'Wasted Spend', 'G-WS1' => 'Wasted Spend',
        'G20' => 'Keywords & QS', 'G21' => 'Keywords & QS', 'G22' => 'Keywords & QS', 'G23' => 'Keywords & QS', 'G24' => 'Keywords & QS', 'G25' => 'Keywords & QS',
        'G-KW1' => 'Keywords & QS', 'G-KW2' => 'Keywords & QS',
        'G26' => 'Ads & Assets', 'G27' => 'Ads & Assets', 'G28' => 'Ads & Assets', 'G29' => 'Ads & Assets', 'G30' => 'Ads & Assets', 'G31' => 'Ads & Assets', 'G32' => 'Ads & Assets', 'G33' => 'Ads & Assets', 'G34' => 'Ads & Assets', 'G35' => 'Ads & Assets',
        'G-AD1' => 'Ads & Assets', 'G-AD2' => 'Ads & Assets',
        'G-PM1' => 'Ads & Assets', 'G-PM2' => 'Ads & Assets', 'G-PM3' => 'Ads & Assets', 'G-PM4' => 'Ads & Assets', 'G-PM5' => 'Ads & Assets', 'G-PM6' => 'Ads & Assets',
        'G-DG1' => 'Ads & Assets', 'G-DG2' => 'Ads & Assets', 'G-DG3' => 'Ads & Assets', 'G-AI1' => 'Ads & Assets',
        'G42' => 'Conversion Tracking', 'G43' => 'Conversion Tracking', 'G44' => 'Conversion Tracking', 'G45' => 'Conversion Tracking', 'G46' => 'Conversion Tracking',
        'G47' => 'Conversion Tracking', 'G48' => 'Conversion Tracking', 'G49' => 'Conversion Tracking', 'G-CT1' => 'Conversion Tracking', 'G-CT2' => 'Conversion Tracking', 'G-CT3' => 'Conversion Tracking', 'G-CTV1' => 'Conversion Tracking',
        'G50' => 'Settings & Targeting', 'G51' => 'Settings & Targeting', 'G52' => 'Settings & Targeting', 'G53' => 'Settings & Targeting', 'G54' => 'Settings & Targeting', 'G55' => 'Settings & Targeting',
        'G56' => 'Settings & Targeting', 'G57' => 'Settings & Targeting', 'G58' => 'Settings & Targeting', 'G59' => 'Settings & Targeting', 'G60' => 'Settings & Targeting', 'G61' => 'Settings & Targeting',
        'G36' => 'Settings & Targeting', 'G37' => 'Settings & Targeting', 'G38' => 'Settings & Targeting', 'G39' => 'Settings & Targeting', 'G40' => 'Settings & Targeting', 'G41' => 'Settings & Targeting',
    ];

    /** @var array<string, array{why_it_matters?: string, how_we_measure?: string, thresholds?: string}> */
    private const OVERRIDES = [
        'G16' => [
            'why_it_matters' => 'Spend on search queries that never convert drains budget and hides true intent.',
            'how_we_measure' => 'Sum cost for search terms with cost > $10 and conversions = 0 in the reporting window; compare to total search-term spend.',
            'thresholds' => 'WARN if wasted share is 5–15%; FAIL above 15%; PASS below 5%.',
        ],
        'G-WS1' => [
            'why_it_matters' => 'Keywords with many clicks but zero conversions usually indicate intent mismatch, broken tracking, or weak LPs.',
            'how_we_measure' => 'Count keyword_view rows with clicks > 100 and conversions = 0 in the window.',
            'thresholds' => 'WARN if 1–3 such keywords; FAIL if more than 3.',
        ],
        'G42' => [
            'why_it_matters' => 'Without conversion actions, smart bidding and reporting cannot optimize toward business outcomes.',
            'how_we_measure' => 'Count enabled conversion actions (excluding Smart Campaign–only rows).',
        ],
        'G-CT1' => [
            'why_it_matters' => 'Double-counting inflates conversions and breaks bidding targets.',
            'how_we_measure' => 'Heuristic: both WEBSITE and GOOGLE_ANALYTICS_4_IMPORT origins among enabled actions.',
        ],
        'G20' => [
            'why_it_matters' => 'Quality Score affects CPC and rank; low QS increases cost per result.',
            'how_we_measure' => 'Impression-weighted average QS from keyword_view where QS > 0 and impressions > 0.',
            'thresholds' => 'PASS iwQS ≥ 7; WARN 5–7; FAIL < 5.',
        ],
        'G05' => [
            'why_it_matters' => 'Mixing brand and non-brand in one Search campaign makes bidding, reporting, and budget allocation ambiguous.',
            'how_we_measure' => 'Keyword text tokens vs inferred brand tokens from the account descriptive name, per Search campaign.',
        ],
        'G-PM3' => [
            'why_it_matters' => 'PMax can capture brand demand that overlaps Search; unmanaged overlap inflates attributed brand performance.',
            'how_we_measure' => 'Deferred in v1 fetch — manual review of brand search terms in PMax is required.',
        ],
        'G-AI1' => [
            'why_it_matters' => 'AI Max for Search is powerful only with volume, hygiene, and clear goals — premature adoption can destabilize learning.',
            'how_we_measure' => 'Account conversions in window and presence of Search campaigns from campaign_agg.',
        ],
        'G-DG2' => [
            'why_it_matters' => 'Legacy Video (VAC) setups need a deliberate migration path to Demand Gen for measurement and frequency controls.',
            'how_we_measure' => 'Presence of VIDEO channel campaigns in campaign_agg as a legacy-risk heuristic.',
        ],
    ];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $defaults = [
            'why_it_matters' => 'Improving this signal typically lowers wasted spend or improves auction efficiency for the same budget.',
            'how_we_measure' => 'This check uses the Google Ads API snapshot collected at audit time (see Evidence and metrics on this run).',
            'data_source' => 'Google Ads API v23-style resources via the app fetch layer (campaign, keyword_view, search_term_view, conversion_action, etc.).',
            'thresholds' => 'Thresholds are applied in GoogleAuditService for this checklist version; compare status to the numeric metrics attached to the check.',
            'references' => [
                ['label' => 'Google Ads Help', 'url' => 'https://support.google.com/google-ads/'],
            ],
        ];
        $out = [];
        foreach (self::IDS as $id) {
            $sec = self::ID_SECTION[$id] ?? 'General';
            $focus = self::SECTION_FOCUS[$sec] ?? self::SECTION_FOCUS['General'];
            $out[$id] = array_merge($defaults, $focus, self::OVERRIDES[$id] ?? []);
        }

        return $out;
    }
}
