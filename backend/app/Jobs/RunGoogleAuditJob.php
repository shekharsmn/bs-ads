<?php

namespace App\Jobs;

use App\Jobs\Concerns\AbortIfReportCancelled;
use App\Models\Report;
use App\Models\ReportCheck;
use App\Models\ReportSectionScore;
use App\Services\GoogleAdsClientFactory;
use App\Services\GoogleAuditService;
use App\Services\GoogleFetchService;
use App\Services\GoogleReportSnapshot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunGoogleAuditJob implements ShouldQueue
{
    use AbortIfReportCancelled;
    use Queueable;

    public int $timeout = 600;

    public function __construct(public readonly int $reportId) {}

    public function handle(): void
    {
        $report = Report::with('googleAccount')->findOrFail($this->reportId);
        $account = $report->googleAccount;
        if (! $account) {
            throw new \RuntimeException('Report has no Google account.');
        }

        if ($this->reportAbortIfCancelled($report)) {
            return;
        }

        $report->update(['status' => 'running', 'started_at' => now()]);

        try {
            @ini_set('memory_limit', '768M');

            $client = GoogleAdsClientFactory::fromRefreshToken(
                $account->refresh_token,
                $account->login_customer_id
            );
            $period = $report->period ?? 'last_30d';
            $literal = GoogleFetchService::dateLiteralFromPeriod($period);
            $fetcher = new GoogleFetchService($client, $account->customer_id, $literal, forInspector: false);
            $data = $fetcher->fetchAll();
            if ($this->reportAbortIfCancelled($report)) {
                return;
            }
            self::assertCampaignAggConsistentWithSlices($data);

            $auditor = new GoogleAuditService($data);
            $checks = $auditor->runAllChecks();
            $scoring = $auditor->calculateScore($checks);

            foreach ($checks as $check) {
                ReportCheck::create([
                    'report_id' => $report->id,
                    'check_id' => $check['id'],
                    'name' => $check['name'],
                    'section' => $check['section'],
                    'status' => $check['status'],
                    'severity' => $check['severity'],
                    'finding' => $check['finding'],
                    'recommendation' => $check['recommendation'] ?? '',
                    'evidence' => $check['evidence'] ?? null,
                    'metrics' => $check['metrics'] ?? null,
                    'impact_score' => $check['impact_score'] ?? null,
                ]);
            }

            foreach ($scoring['section_scores'] as $section => $score) {
                ReportSectionScore::create([
                    'report_id' => $report->id,
                    'section' => $section,
                    'score' => $score,
                    'weight' => $scoring['weights'][$section],
                ]);
            }

            if ($this->reportAbortIfCancelled($report)) {
                return;
            }

            $report->update([
                'status' => 'complete',
                'health_score' => $scoring['score'],
                'grade' => GoogleAuditService::grade($scoring['score']),
                'completed_at' => now(),
                'google_snapshot' => GoogleReportSnapshot::build($data),
            ]);
        } catch (\Throwable $e) {
            $report->refresh();
            if ($report->status === 'cancelled') {
                return;
            }
            Log::error('RunGoogleAuditJob failed', [
                'report_id' => $report->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $report->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function assertCampaignAggConsistentWithSlices(array $data): void
    {
        $agg = $data['campaign_agg'] ?? [];
        if ($agg !== []) {
            return;
        }
        $keywords = $data['keywords'] ?? [];
        $rsa = $data['rsa_ads'] ?? [];
        if ($keywords === [] && $rsa === []) {
            return;
        }

        throw new \RuntimeException(
            'Google Ads returned keyword or RSA rows but campaign aggregates could not be built. The audit was stopped so scores are not based on an empty campaign snapshot.'
        );
    }

}
