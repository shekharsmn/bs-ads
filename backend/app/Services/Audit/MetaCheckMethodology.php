<?php

namespace App\Services\Audit;

/**
 * Static explainer copy for each Meta audit check id (authoritative, not AI-generated).
 */
final class MetaCheckMethodology
{
    /** @var list<string> */
    private const IDS = [
        'M01', 'M02', 'M03', 'M04', 'M05', 'M06', 'M07', 'M08', 'M09', 'M10',
        'M11', 'M12', 'M13', 'M14', 'M15', 'M16', 'M17', 'M18', 'M19', 'M20', 'M21', 'M22',
    ];

    private const SECTION_FOCUS = [
        'Pixel/CAPI' => [
            'why_it_matters' => 'Browser and server signals power optimization, attribution, and iOS resilience — weak tracking makes every downstream decision noisy.',
            'how_we_measure' => 'Pixels list + last_fired_time, pixel stats event names, custom conversions catalog, and spend vs lead heuristics from ad insights.',
        ],
        'Creative' => [
            'why_it_matters' => 'Creative diversity and healthy frequency keep auction bids efficient; fatigue silently raises costs and hides underperforming angles.',
            'how_we_measure' => 'Per-ad insights (frequency, CTR, impressions), creative object_type mix from the creative fetch, and CTA coverage (partial / deferred where noted).',
        ],
        'Structure' => [
            'why_it_matters' => 'Budget flow, objectives, and bid strategies must match how Meta allocates spend across ad sets and learning phases.',
            'how_we_measure' => 'Campaigns, ad sets, budgets on campaign vs ad set, bid_strategy fields, objectives, destinations, and account-level lead totals.',
        ],
        'Audience' => [
            'why_it_matters' => 'Retargeting, lookalikes, and overlap hygiene keep prospecting efficient and protect remarketing ROAS.',
            'how_we_measure' => 'Custom audience subtypes, lookalike presence, ad set targeting payload presence, and ad set count heuristics for overlap risk.',
        ],
        'General' => [
            'why_it_matters' => 'This checklist item supports overall account health and measurement quality.',
            'how_we_measure' => 'See the Evidence and metrics payload saved with this check for the exact inputs used on this run.',
        ],
    ];

    /** @var array<string, string> */
    private const ID_SECTION = [
        'M01' => 'Pixel/CAPI', 'M02' => 'Pixel/CAPI', 'M03' => 'Pixel/CAPI', 'M04' => 'Pixel/CAPI', 'M05' => 'Pixel/CAPI',
        'M06' => 'Creative', 'M07' => 'Creative', 'M08' => 'Creative', 'M09' => 'Creative',
        'M10' => 'Structure', 'M11' => 'Structure', 'M12' => 'Structure', 'M13' => 'Structure', 'M14' => 'Structure', 'M19' => 'Structure', 'M21' => 'Structure', 'M22' => 'Structure',
        'M15' => 'Audience', 'M16' => 'Audience', 'M17' => 'Audience', 'M18' => 'Audience', 'M20' => 'Audience',
    ];

    /** @var array<string, array{why_it_matters?: string, how_we_measure?: string, thresholds?: string}> */
    private const OVERRIDES = [
        'M01' => [
            'thresholds' => 'FAIL if zero pixels; WARN if pixels exist but none show recent last_fired_time; PASS when at least one pixel appears active.',
        ],
        'M02' => [
            'how_we_measure' => 'Union of event_name values from pixel stats rows, intersected with a B2B-oriented allowlist (Lead, CompleteRegistration, etc.).',
            'thresholds' => 'FAIL if no allowlisted events appear in the union.',
        ],
        'M06' => [
            'thresholds' => 'FAIL if more than two ads with frequency > 3.5; WARN if 1–2; PASS otherwise.',
        ],
        'M08' => [
            'thresholds' => 'Among ads with impressions > 500, FAIL if >50% have CTR < 0.5%; WARN if >20%; PASS otherwise.',
        ],
        'M19' => [
            'how_we_measure' => 'Sums spend and lead-type actions from ad-level insights in the audit window.',
            'thresholds' => 'FAIL if spend > $500 and zero leads; WARN on high implied CPL when leads > 0.',
        ],
    ];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $defaults = [
            'why_it_matters' => 'Stronger signals here improve attribution, creative learning, and efficient spend.',
            'how_we_measure' => 'Uses Meta Marketing API data fetched at audit time (see Evidence and metrics).',
            'data_source' => 'Meta Graph API via MetaFetchService.',
            'thresholds' => 'See MetaAuditService scoring for this checklist version.',
            'references' => [
                ['label' => 'Meta Business Help', 'url' => 'https://www.facebook.com/business/help'],
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
