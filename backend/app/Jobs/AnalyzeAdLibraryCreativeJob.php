<?php

namespace App\Jobs;

use App\Models\AdLibraryAudit;
use App\Models\AdLibraryAuditCreative;
use App\Services\AdLibraryAudit\AdLibraryAuditWorkflow;
use App\Services\AdLibraryAudit\RetryableAiException;
use App\Services\WorkflowActivityService;
use App\Services\WorkflowCoordinator;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class AnalyzeAdLibraryCreativeJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function backoff(): array
    {
        return config('workloads.policies.ad_library_analysis.backoff', [60, 180]);
    }

    public function __construct(public readonly int $creativeId)
    {
        $this->onQueue(config('workloads.queues.ad_library_analysis', 'ad-library-analysis'));
        $this->timeout = config('workloads.policies.ad_library_analysis.timeout', 900);
        $this->tries = config('workloads.policies.ad_library_analysis.tries', 3);
    }

    public function handle(AdLibraryAuditWorkflow $workflow, ?WorkflowActivityService $activities = null, ?WorkflowCoordinator $workflows = null): void
    {
        $activities ??= app(WorkflowActivityService::class);
        $workflows ??= app(WorkflowCoordinator::class);
        if ($this->batch()?->cancelled()) {
            return;
        }
        $creative = AdLibraryAuditCreative::with('audit')->find($this->creativeId);
        if (! $creative || ! $creative->audit) {
            return;
        }
        if ($creative->audit->status !== 'analyzing_creatives') {
            return;
        }
        $claimed = AdLibraryAuditCreative::query()->whereKey($creative->id)->where('processing_status', 'pending')
            ->update(['processing_status' => 'running', 'error_message' => null]);
        if ($claimed !== 1) {
            return;
        }
        $creative->refresh();
        $attempt = $workflows->start($activities->sync($creative->audit), 'analyzing_creative', 'ad-audit-creative:'.$creative->id.':'.($this->job?->uuid() ?? Str::uuid()), ['creative_id' => $creative->id]);
        try {
            $workflow->analyzeCreative($creative->fresh());
            $workflows->complete($attempt, 'creative_complete', ['creative_id' => $creative->id], projectToActivity: false);
        } catch (RetryableAiException $e) {
            $creative->update(['processing_status' => 'pending']);
            $workflows->fail($attempt, $e, 'creative_retry', projectToActivity: false);
            throw $e;
        } catch (Throwable $e) {
            $creative->update(['processing_status' => 'failed', 'error_message' => 'This creative could not be classified.']);
            $workflows->fail($attempt, $e, 'creative_failed', projectToActivity: false);
        }
        $this->refreshAudit($creative->audit);
    }

    public function failed(?Throwable $e): void
    {
        $creative = AdLibraryAuditCreative::with('audit')->find($this->creativeId);
        if (! $creative) {
            return;
        }
        if (! in_array($creative->processing_status, ['complete', 'skipped'], true)) {
            $creative->update($creative->raw_ai_response !== null
                ? ['processing_status' => 'complete', 'error_message' => null]
                : ['processing_status' => 'failed', 'error_message' => 'This creative could not be classified after retrying.']);
        }
        $this->refreshAudit($creative->audit);
    }

    private function refreshAudit(AdLibraryAudit $audit): void
    {
        $counts = $audit->creatives()->reorder()->where('processing_status', '!=', 'skipped')->selectRaw("SUM(CASE WHEN processing_status = 'complete' THEN 1 ELSE 0 END) completed, SUM(CASE WHEN processing_status = 'failed' THEN 1 ELSE 0 END) failed, COUNT(*) total")->first();
        $audit->update(['progress_total' => (int) $counts->total, 'progress_completed' => (int) $counts->completed, 'progress_failed' => (int) $counts->failed]);
        if ($audit->fresh()->status === 'analyzing_creatives' && (int) $counts->total > 0 && (int) $counts->completed + (int) $counts->failed >= (int) $counts->total) {
            $audit->update(['status' => 'creative_review']);
        }
    }
}
