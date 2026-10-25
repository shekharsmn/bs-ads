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
use App\Services\WorkflowActivityService;
use App\Services\WorkflowCoordinator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RunGoogleAuditJob implements ShouldBeUnique, ShouldQueue
{
    use AbortIfReportCancelled;
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    public function __construct(public readonly int $reportId)
    {
        $this->onQueue(config('workloads.queues.audits', 'audits'));
        $this->timeout = config('workloads.policies.audits.timeout', 600);
        $this->tries = config('workloads.policies.audits.tries', 3);
    }

    public function uniqueId(): string
    {
        return 'google-audit:'.$this->reportId;
    }

    public function handle(WorkflowActivityService $activities, WorkflowCoordinator $workflows): void
    {
        $report = Report::with(['googleAccount', 'adAccount.connection'])->findOrFail($this->reportId);
        $account = $report->googleAccount;
        $operational = $report->adAccount;
        if (! $account && ! $operational) {
            throw new \RuntimeException('Report has no Google account.');
        }

        if ($this->reportAbortIfCancelled($report)) {
            return;
        }

        $report->update(['status' => 'running', 'started_at' => now()]);
        $attempt = $workflows->start($activities->sync($report), 'fetching', 'google-audit:'.$report->id.':'.($this->job?->uuid() ?? Str::uuid()));

        try {
            @ini_set('memory_limit', '768M');

            $client = GoogleAdsClientFactory::fromRefreshToken(
                $account?->refresh_token ?? $operational->connection->credentials['refresh_token'],
                $account?->login_customer_id ?? ($operational->manager_external_id ?? $operational->connection->credentials['login_customer_id'] ?? $operational->connection->manager_external_id)
            );
            $period = $report->period ?? 'last_30d';
            $literal = GoogleFetchService::dateLiteralFromPeriod($period);
            $fetcher = new GoogleFetchService($client, $account?->customer_id ?? $operational->external_id, $literal, forInspector: false);
            $data = $fetcher->fetchAll();
            if ($this->reportAbortIfCancelled($report)) {
                $workflows->cancel($attempt);

                return;
            }
            $workflows->heartbeat($attempt, 'evaluating');
            self::assertCampaignAggConsistentWithSlices($data);

            $auditor = new GoogleAuditService($data);
            $checks = $auditor->runAllChecks();
            $scoring = $auditor->calculateScore($checks);

            DB::transaction(function () use ($report, $checks, $scoring, $data) {
                $report->checks()->delete();
                $report->sectionScores()->delete();
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
            });
            $workflows->complete($attempt, 'complete');
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
            $workflows->fail($attempt, $e, 'audit_failed');
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
