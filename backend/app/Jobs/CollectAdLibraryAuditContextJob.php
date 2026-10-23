<?php

namespace App\Jobs;

use App\Models\AdLibraryAudit;
use App\Services\AdLibraryAudit\AdLibraryAuditWorkflow;
use App\Services\AdLibraryAudit\RetryableAiException;
use App\Services\AdLibraryAudit\RetryableContextException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CollectAdLibraryAuditContextJob implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 900;
    public function backoff(): array { return [60, 180]; }
    public function __construct(public readonly int $auditId) { $this->onQueue('ad-library-analysis'); }

    public function handle(AdLibraryAuditWorkflow $workflow): void
    {
        $audit = AdLibraryAudit::findOrFail($this->auditId);
        if (! in_array($audit->status, ['draft', 'collecting_context', 'failed'], true)) return;
        $audit->update(['status' => 'collecting_context', 'failed_stage' => null, 'error_message' => null]);
        try { $workflow->collectContext($audit->fresh()); }
        catch (RetryableAiException|RetryableContextException $e) { throw $e; }
        catch (Throwable $e) { $audit->update(['status' => 'failed', 'failed_stage' => 'context', 'error_message' => 'Context analysis failed.']); }
    }

    public function failed(?Throwable $e): void
    {
        $message = $e instanceof RetryableContextException
            ? 'The website continued to rate-limit or reject context collection. Wait a few minutes and try Recollect again, or enter it manually.'
            : 'Context analysis could not be completed.';
        AdLibraryAudit::whereKey($this->auditId)->update(['status' => 'failed', 'failed_stage' => 'context', 'error_message' => $message]);
    }
}
