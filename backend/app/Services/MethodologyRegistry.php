<?php

namespace App\Services;

class MethodologyRegistry
{
    private const DEFINITIONS = [
        'account-audit' => [
            'version' => '2.0.0', 'released_at' => '2026-10-24',
            'summary' => 'Platform account health scoring with explicit evidence and section weights.',
            'checks' => ['account_setup', 'tracking', 'campaign_structure', 'creative', 'performance'],
            'previous_version' => 'legacy-1.0', 'changes' => ['Added stored evidence and stable scoring metadata.', 'Added an explicit methodology checksum.'],
        ],
        'performance-report' => [
            'version' => '2.0.0', 'released_at' => '2026-10-24',
            'summary' => 'Period snapshot with previous-period comparison, freshness, alerts, and client goals.',
            'checks' => ['summary', 'trends', 'campaigns', 'alerts', 'data_freshness'],
            'previous_version' => 'legacy-1.0', 'changes' => ['Added comparison and data-freshness sections.', 'Snapshots now preserve the exact methodology.'],
        ],
        'content-audit' => [
            'version' => '2.0.0', 'released_at' => '2026-10-24',
            'summary' => 'Evidence-backed creative classification and content observations without performance claims.',
            'checks' => ['context', 'personas', 'creative_classification', 'confidence_review', 'insights'],
            'previous_version' => 'legacy-1.0', 'changes' => ['Added confidence review and evidence requirements.', 'Formalized the no-performance-claims boundary.'],
        ],
    ];

    public function all(): array
    {
        return collect(self::DEFINITIONS)->map(fn (array $definition, string $key) => $this->snapshot($key))->values()->all();
    }

    public function snapshot(string $key): array
    {
        $definition = self::DEFINITIONS[$key] ?? throw new \InvalidArgumentException('Unknown methodology.');

        return ['key' => $key, ...$definition, 'checksum' => hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR))];
    }

    public function columns(string $key): array
    {
        $snapshot = $this->snapshot($key);

        return ['methodology_key' => $key, 'methodology_version' => $snapshot['version'], 'methodology_snapshot' => $snapshot];
    }

    public function compare(string $key, string $from): array
    {
        $current = $this->snapshot($key);

        return ['key' => $key, 'from' => $from, 'to' => $current['version'], 'changes' => $from === $current['version'] ? [] : $current['changes'], 'current_checks' => $current['checks']];
    }
}
