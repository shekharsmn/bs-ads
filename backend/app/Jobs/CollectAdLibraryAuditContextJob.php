<?php

namespace App\Jobs;

use App\Models\AdLibraryAudit;
use App\Services\AdLibraryAudit\AdLibraryAuditWorkflow;
use App\Services\AdLibraryAudit\RetryableAiException;
use App\Services\WorkflowActivityService;
use App\Services\WorkflowCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class CollectAdLibraryAuditContextJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function backoff(): array
    {
        return config('workloads.policies.ad_library_analysis.backoff', [60, 180]);
    }

    public function __construct(public readonly int $auditId)
    {
        $this->onQueue(config('workloads.queues.ad_library_analysis', 'ad-library-analysis'));
        $this->timeout = config('workloads.policies.ad_library_analysis.timeout', 900);
        $this->tries = config('workloads.policies.ad_library_analysis.tries', 3);
    }

    public function handle(AdLibraryAuditWorkflow $workflow, WorkflowActivityService $activities, WorkflowCoordinator $workflows): void
    {
        $audit = AdLibraryAudit::findOrFail($this->auditId);
        if (! in_array($audit->status, ['draft', 'collecting_context', 'failed'], true)) {
            return;
        }
        $attempt = $workflows->start($activities->sync($audit), 'collecting_context', 'ad-audit-context:'.$audit->id.':'.($this->job?->uuid() ?? Str::uuid()));
        $audit->update(['status' => 'collecting_context', 'failed_stage' => null, 'error_message' => null]);
        try {
            $workflow->collectContext($audit->fresh());
            $workflows->complete($attempt, 'context_ready', projectToActivity: false);
        } catch (RetryableAiException $e) {
            $workflows->fail($attempt, $e, 'context_retry', projectToActivity: false);
            throw $e;
        } catch (Throwable $e) {
            $audit->update(['status' => 'failed', 'failed_stage' => 'context', 'error_message' => 'Context analysis failed.']);
            $workflows->fail($attempt, $e, 'context_failed');
        }
    }

    public function failed(?Throwable $e): void
    {
        AdLibraryAudit::whereKey($this->auditId)->update(['status' => 'failed', 'failed_stage' => 'context', 'error_message' => 'Context analysis could not be completed.']);
    }
}
