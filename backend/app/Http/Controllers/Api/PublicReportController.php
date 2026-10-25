<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Services\Audit\GoogleCheckMethodology;
use App\Services\Audit\MetaCheckMethodology;
use Illuminate\Http\Request;

class PublicReportController extends Controller
{
    /**
     * Read-only report payload for shared links (no account identifiers).
     */
    public function show(Request $request, string $token)
    {
        $report = Report::query()
            ->where('public_token', $token)
            ->where('status', 'complete')
            ->with(['sectionScores', 'checks'])
            ->firstOrFail();

        $payload = $report->only([
            'id', 'platform', 'period', 'health_score', 'grade',
            'google_snapshot', 'meta_snapshot', 'completed_at', 'created_at',
        ]);
        $payload['checks'] = $report->checks->map(fn ($c) => $c->only([
            'check_id', 'name', 'section', 'status', 'severity',
            'finding', 'recommendation', 'evidence', 'metrics', 'impact_score',
        ]));
        $payload['section_scores'] = $report->sectionScores;

        $payload['methodology'] = $report->platform === 'google'
            ? GoogleCheckMethodology::all()
            : MetaCheckMethodology::all();

        return response()->json($payload)->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
