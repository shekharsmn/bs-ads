<?php

namespace App\Jobs;

use App\Jobs\Concerns\AbortIfReportCancelled;
use App\Models\Report;
use App\Models\ReportCheck;
use App\Models\ReportSectionScore;
use App\Models\ReportAd;
use App\Models\AdCopyAnalysis;
use App\Models\AdVideoMetric;
use App\Models\AdVisualAnalysis;
use App\Models\CreativeSummary;
use App\Services\MetaFetchService;
use App\Services\MetaAuditService;
use App\Services\MetaCreativeService;
use App\Services\MetaReportSnapshot;
use App\Services\GeminiAnalyzerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunMetaAuditJob implements ShouldQueue
{
    use AbortIfReportCancelled;
    use Queueable;

    public int $timeout = 300;

    public function __construct(public readonly int $reportId)
    {
    }

    public function handle(): void
    {
        $report = Report::with(['metaAccount', 'adAccount.connection'])->findOrFail($this->reportId);
        $account = $report->metaAccount;
        $operational = $report->adAccount;

        if ($this->reportAbortIfCancelled($report)) {
            return;
        }

        $report->update(['status' => 'running', 'started_at' => now()]);

        try {
            // 1. Fetch all data from Meta API
            $fetcher = new MetaFetchService(
                $account?->account_id ?? $operational->external_id,
                $account?->access_token ?? $operational->connection->credentials['access_token'],
                $account?->app_id ?? ($operational->connection->credentials['app_id'] ?? ''),
                $account?->app_secret ?? ($operational->connection->credentials['app_secret'] ?? ''),
            );
            $fetcher->setPeriod($report->period ?? 'last_30d');
            $data = $fetcher->fetchAll();
            if ($this->reportAbortIfCancelled($report)) {
                return;
            }

            // 2. Run audit checks
            $auditor = new MetaAuditService($data);
            $checks = $auditor->runAllChecks();
            $scoring = $auditor->calculateScore($checks);

            // 3. Persist checks
            foreach ($checks as $check) {
                ReportCheck::create([
                    'report_id'      => $report->id,
                    'check_id'       => $check['id'],
                    'name'           => $check['name'],
                    'section'        => $check['section'],
                    'status'         => $check['status'],
                    'severity'       => $check['severity'],
                    'finding'        => $check['finding'],
                    'recommendation' => $check['recommendation'] ?? '',
                    'evidence'       => $check['evidence'] ?? null,
                    'metrics'        => $check['metrics'] ?? null,
                    'impact_score'   => $check['impact_score'] ?? null,
                ]);
            }

            // 4. Persist section scores
            foreach ($scoring['section_scores'] as $section => $score) {
                ReportSectionScore::create([
                    'report_id' => $report->id,
                    'section'   => $section,
                    'score'     => $score,
                    'weight'    => $scoring['weights'][$section],
                ]);
            }

            if ($this->reportAbortIfCancelled($report)) {
                return;
            }

            // 5. Run creative audit
            $creativeService = new MetaCreativeService();
            [$perAd, $summary] = $creativeService->runAudit($data);

            // 6. Gemini visual analysis
            $gemini = new GeminiAnalyzerService();
            $gemini->setLogContext($report->user_id, $report->id, $report->meta_account_id);
            $geminiTokens = ['input_tokens' => 0, 'output_tokens' => 0];
            $creatives = $data['creatives'] ?? [];
            $videos = $data['videos'] ?? [];
            $adInsightsMap = $data['adInsightsMap'] ?? [];

            foreach ($perAd as $i => $adData) {
                if ($i % 3 === 0 && $this->reportAbortIfCancelled($report)) {
                    return;
                }
                $reportAd = ReportAd::create([
                    'report_id'  => $report->id,
                    'meta_ad_id' => $adData['ad_id'],
                    'ad_name'    => $adData['ad_name'],
                    'format'     => $adData['format'],
                    'spend'      => $adData['spend'],
                    'impressions'=> $adData['impressions'],
                    'ctr'        => $adData['ctr'],
                    'cpc'        => $adData['cpc'],
                    'frequency'  => $adData['frequency'],
                    'leads'      => $adData['leads'],
                    'perf_flags' => $adData['perf_flags'],
                    'copy'       => $adData['copy'],
                ]);

                AdCopyAnalysis::create([
                    'report_ad_id' => $reportAd->id,
                    'score'        => $adData['copy_analysis']['score'],
                    'issues'       => $adData['copy_analysis']['issues'],
                    'suggestions'  => $adData['copy_analysis']['suggestions'],
                ]);

                if ($vm = $adData['video_metrics'] ?? null) {
                    AdVideoMetric::create([
                        'report_ad_id'  => $reportAd->id,
                        'hook_rate'     => $vm['hook_rate'],
                        'thruplay_rate' => $vm['thruplay_rate'],
                        'avg_watch_time'=> $vm['avg_watch_time'],
                        'duration'      => $vm['duration'],
                    ]);
                }

                // Gemini analysis
                if ($gemini->isConfigured()) {
                    $ins = $adInsightsMap[$adData['ad_id']] ?? [];
                    $copy = $adData['copy'];
                    $format = $adData['format'];
                    $creative = collect($creatives)->firstWhere('_ad_id', $adData['ad_id']) ?? [];

                    if (in_array($format, ['VIDEO', 'DYNAMIC_VIDEO'])) {
                        $spec = $creative['object_story_spec'] ?? [];
                        $vidId = $spec['video_data']['video_id'] ?? null;
                        $vidInfo = $vidId ? ($videos[$vidId] ?? []) : [];
                        $vm = $adData['video_metrics'] ?? [];
                        $result = $gemini->analyzeVideo(
                            $vidInfo, $copy, $ins, $adData['ad_name'],
                            (float)($vm['hook_rate'] ?? 0),
                            (float)($vm['thruplay_rate'] ?? 0),
                            (float)($vm['avg_watch_time'] ?? 0),
                        );
                    } else {
                        $spec = $creative['object_story_spec'] ?? [];
                        $linkData = $spec['link_data'] ?? [];
                        $feed = $creative['asset_feed_spec'] ?? [];
                        $imageUrl = $linkData['picture']
                            ?? $creative['image_url']
                            ?? $creative['thumbnail_url']
                            ?? ($feed['images'][0]['url'] ?? null);
                        $result = $imageUrl
                            ? $gemini->analyzeStatic($imageUrl, $copy, $ins, $adData['ad_name'])
                            : ['error' => 'No image URL found'];
                    }

                    AdVisualAnalysis::create([
                        'report_ad_id' => $reportAd->id,
                        'type'         => $result['type'] ?? (in_array($format, ['VIDEO', 'DYNAMIC_VIDEO']) ? 'video' : 'static'),
                        'analysis_text'=> $result['analysis_text'] ?? null,
                        'transcript'   => $result['transcript'] ?? null,
                        'has_frames'   => $result['has_frames'] ?? false,
                        'error'        => $result['error'] ?? null,
                    ]);

                    $usage = $gemini->getTokenUsage();
                    $geminiTokens['input_tokens']  = $usage['input_tokens'];
                    $geminiTokens['output_tokens'] = $usage['output_tokens'];
                }
            }

            if ($this->reportAbortIfCancelled($report)) {
                return;
            }

            // 7. Creative summary
            CreativeSummary::create([
                'report_id'       => $report->id,
                'format_counts'   => $summary['format_counts'],
                'format_spend'    => $summary['format_spend'],
                'format_leads'    => $summary['format_leads'],
                'cta_distribution'=> $summary['cta_distribution'],
                'common_issues'   => $summary['common_issues'],
            ]);

            if ($this->reportAbortIfCancelled($report)) {
                return;
            }

            // 8. Update report with final score and token usage
            $report->update([
                'status'               => 'complete',
                'health_score'         => $scoring['score'],
                'grade'                => MetaAuditService::grade($scoring['score']),
                'completed_at'         => now(),
                'gemini_input_tokens'  => $geminiTokens['input_tokens'],
                'gemini_output_tokens' => $geminiTokens['output_tokens'],
                'meta_snapshot'        => MetaReportSnapshot::build($data),
            ]);
        } catch (\Throwable $e) {
            $report->refresh();
            if ($report->status === 'cancelled') {
                return;
            }
            Log::error('RunMetaAuditJob failed', [
                'report_id' => $report->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $report->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            throw $e;
        }
    }
}
