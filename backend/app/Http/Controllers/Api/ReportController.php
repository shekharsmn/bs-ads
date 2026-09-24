<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunGoogleAuditJob;
use App\Jobs\RunMetaAuditJob;
use App\Models\Report;
use App\Models\MetaAccount;
use App\Models\GoogleAccount;
use App\Services\Audit\GoogleCheckMethodology;
use App\Services\Audit\MetaCheckMethodology;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $reports = $request->user()->reports()
            ->with([
                'metaAccount:id,label,account_name',
                'googleAccount:id,label,customer_id,account_name',
            ])
            ->orderByDesc('created_at')
            ->get(['id', 'platform', 'meta_account_id', 'google_account_id', 'status', 'health_score', 'grade', 'period', 'error_message', 'started_at', 'completed_at', 'created_at']);
        return response()->json($reports);
    }

    public function store(Request $request)
    {
        if (! $request->has('platform') && $request->has('meta_account_id')) {
            $request->merge(['platform' => 'meta']);
        }

        $data = $request->validate([
            'platform' => 'required|string|in:meta,google',
            'meta_account_id' => 'exclude_unless:platform,meta|required|integer|exists:meta_accounts,id',
            'google_account_id' => 'exclude_unless:platform,google|required|integer|exists:google_accounts,id',
            'period' => 'nullable|string|in:last_7d,last_30d,last_90d',
        ]);

        if ($data['platform'] === 'meta') {
            $account = MetaAccount::findOrFail($data['meta_account_id']);
            if ($account->user_id !== $request->user()->id) {
                abort(403);
            }
            $report = Report::create([
                'user_id' => $request->user()->id,
                'platform' => 'meta',
                'meta_account_id' => $account->id,
                'google_account_id' => null,
                'status' => 'pending',
                'period' => $data['period'] ?? 'last_30d',
            ]);
            RunMetaAuditJob::dispatch($report->id);
        } else {
            $account = GoogleAccount::findOrFail($data['google_account_id']);
            if ($account->user_id !== $request->user()->id) {
                abort(403);
            }
            $report = Report::create([
                'user_id' => $request->user()->id,
                'platform' => 'google',
                'meta_account_id' => null,
                'google_account_id' => $account->id,
                'status' => 'pending',
                'period' => $data['period'] ?? 'last_30d',
            ]);
            RunGoogleAuditJob::dispatch($report->id);
        }

        return response()->json(['report_id' => $report->id, 'status' => 'pending'], 202);
    }

    public function show(Request $request, Report $report)
    {
        if ($report->user_id !== $request->user()->id) abort(403);

        $with = [
            'metaAccount:id,label,account_name,currency',
            'googleAccount:id,label,customer_id,account_name,currency,time_zone',
            'sectionScores',
            'checks',
        ];
        if ($report->platform === 'meta') {
            $with = array_merge($with, [
                'creativeSummary',
                'ads.copyAnalysis',
                'ads.videoMetrics',
                'ads.visualAnalysis',
            ]);
        }

        $report->load($with);

        $payload = $report->toArray();
        $payload['methodology'] = $report->platform === 'google'
            ? GoogleCheckMethodology::all()
            : MetaCheckMethodology::all();

        return response()->json($payload);
    }

    /**
     * Compare this report to the most recent prior completed audit for the same account and period.
     */
    public function compare(Request $request, Report $report)
    {
        if ($report->user_id !== $request->user()->id) {
            abort(403);
        }

        $report->load(['checks', 'sectionScores']);

        $q = Report::query()
            ->where('user_id', $report->user_id)
            ->where('platform', $report->platform)
            ->where('status', 'complete')
            ->where('id', '<>', $report->id)
            ->where('period', $report->period);

        if ($report->platform === 'google') {
            $q->where('google_account_id', $report->google_account_id);
        } else {
            $q->where('meta_account_id', $report->meta_account_id);
        }

        $previous = $q->orderByDesc('completed_at')->first();
        if (! $previous) {
            return response()->json([
                'previous' => null,
                'score_delta' => null,
                'section_deltas' => [],
                'kpi_deltas' => [],
                'resolved_check_ids' => [],
                'new_check_ids' => [],
            ]);
        }

        $previous->load(['checks', 'sectionScores']);

        $prevById = $previous->checks->keyBy('check_id');
        $currById = $report->checks->keyBy('check_id');

        $resolved = [];
        foreach ($previous->checks as $pc) {
            if (in_array($pc->status, ['PASS', 'SKIP'], true)) {
                continue;
            }
            $id = $pc->check_id;
            $cc = $currById->get($id);
            if ($cc && in_array($cc->status, ['PASS', 'SKIP'], true)) {
                $resolved[] = $id;
            }
        }

        $new = [];
        foreach ($report->checks as $cc) {
            if (in_array($cc->status, ['PASS', 'SKIP'], true)) {
                continue;
            }
            $id = $cc->check_id;
            $pc = $prevById->get($id);
            if (! $pc || in_array($pc->status, ['PASS', 'SKIP'], true)) {
                $new[] = $id;
            }
        }

        $sectionDeltas = [];
        foreach ($report->sectionScores as $s) {
            $p = $previous->sectionScores->firstWhere('section', $s->section);
            $sectionDeltas[] = [
                'section' => $s->section,
                'current' => $s->score,
                'previous' => $p?->score,
                'delta' => $p ? ($s->score - $p->score) : null,
            ];
        }

        $currKpis = ($report->google_snapshot ?? [])['kpis'] ?? ($report->meta_snapshot ?? [])['kpis'] ?? [];
        $prevKpis = ($previous->google_snapshot ?? [])['kpis'] ?? ($previous->meta_snapshot ?? [])['kpis'] ?? [];
        $kpiDeltas = [];
        foreach (array_unique(array_merge(array_keys($currKpis), array_keys($prevKpis))) as $k) {
            $c = $currKpis[$k] ?? null;
            $p = $prevKpis[$k] ?? null;
            if (is_numeric($c) && is_numeric($p) && (float) $p != 0.0) {
                $kpiDeltas[$k] = round((((float) $c - (float) $p) / abs((float) $p)) * 100, 2);
            } elseif (is_numeric($c) && is_numeric($p)) {
                $kpiDeltas[$k] = null;
            }
        }

        return response()->json([
            'previous' => [
                'id' => $previous->id,
                'completed_at' => $previous->completed_at,
                'health_score' => $previous->health_score,
                'grade' => $previous->grade,
            ],
            'score_delta' => ($report->health_score ?? 0) - ($previous->health_score ?? 0),
            'section_deltas' => $sectionDeltas,
            'kpi_deltas' => $kpiDeltas,
            'resolved_check_ids' => $resolved,
            'new_check_ids' => $new,
        ]);
    }

    public function exportCsv(Request $request, Report $report): StreamedResponse
    {
        if ($report->user_id !== $request->user()->id) {
            abort(403);
        }
        $report->load('checks');

        $filename = 'report-'.$report->id.'-checks.csv';

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['check_id', 'name', 'section', 'status', 'severity', 'impact_score', 'finding', 'recommendation', 'metrics_json', 'evidence_json']);
            foreach ($report->checks as $c) {
                $metrics = json_encode($c->metrics ?? [], JSON_UNESCAPED_UNICODE);
                $evidence = json_encode($c->evidence ?? [], JSON_UNESCAPED_UNICODE);
                fputcsv($out, [
                    $c->check_id,
                    $c->name,
                    $c->section,
                    $c->status,
                    $c->severity,
                    $c->impact_score,
                    $c->finding,
                    $c->recommendation,
                    $metrics,
                    $evidence,
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function enableShare(Request $request, Report $report)
    {
        if ($report->user_id !== $request->user()->id) {
            abort(403);
        }
        if ($report->status !== 'complete') {
            return response()->json(['message' => 'Only completed reports can be shared.'], 422);
        }

        if (! $report->public_token) {
            $report->update(['public_token' => Str::random(48)]);
            $report->refresh();
        }

        return response()->json([
            'token' => $report->public_token,
            'path' => '/api/public/reports/'.$report->public_token,
        ]);
    }

    public function disableShare(Request $request, Report $report)
    {
        if ($report->user_id !== $request->user()->id) {
            abort(403);
        }
        $report->update(['public_token' => null]);

        return response()->json(['message' => 'Share link revoked.']);
    }

    public function status(Request $request, Report $report)
    {
        if ($report->user_id !== $request->user()->id) {
            abort(403);
        }

        return response()->json([
            'status' => $report->status,
            'health_score' => $report->health_score,
            'grade' => $report->grade,
            'error_message' => $report->error_message,
            'completed_at' => $report->completed_at,
        ]);
    }

    /**
     * Stop a pending or in-progress audit. The worker exits at the next safe checkpoint.
     */
    public function cancel(Request $request, Report $report)
    {
        if ($report->user_id !== $request->user()->id) {
            abort(403);
        }

        if (in_array($report->status, ['complete', 'failed', 'cancelled'], true)) {
            return response()->json([
                'message' => 'This audit is already finished.',
                'status' => $report->status,
            ], 422);
        }

        if ($report->status === 'pending') {
            $report->update([
                'cancel_requested_at' => now(),
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'completed_at' => now(),
                'error_message' => 'Cancelled before start.',
            ]);

            return response()->json(['status' => 'cancelled', 'message' => 'Audit cancelled.']);
        }

        $report->update(['cancel_requested_at' => now()]);

        return response()->json([
            'status' => 'running',
            'message' => 'Stop requested. The audit will end shortly after the current fetch step.',
        ]);
    }

    public function destroy(Request $request, Report $report)
    {
        if ($report->user_id !== $request->user()->id) abort(403);
        $report->delete();
        return response()->json(['message' => 'Report deleted']);
    }
}
