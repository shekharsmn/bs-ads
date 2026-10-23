<?php

namespace App\Jobs;

use App\Models\AdLibraryAudit;
use App\Models\AdLibraryAuditCreative;
use App\Services\AdLibraryAudit\AdLibraryAuditWorkflow;
use App\Services\AdLibraryAudit\RetryableAiException;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalyzeAdLibraryCreativeJob implements ShouldQueue
{
    use Batchable, Queueable;
    public int $tries = 3;
    public int $timeout = 900;
    public function backoff(): array { return [60, 180]; }
    public function __construct(public readonly int $creativeId) { $this->onQueue('ad-library-analysis'); }
    public function handle(AdLibraryAuditWorkflow $workflow): void
    {
        if ($this->batch()?->cancelled()) return;
        $creative = AdLibraryAuditCreative::with('audit')->findOrFail($this->creativeId);
        if ($creative->processing_status === 'complete') { $this->refreshAudit($creative->audit); return; }
        $creative->update(['processing_status' => 'running', 'error_message' => null]);
        try { $workflow->analyzeCreative($creative->fresh()); }
        catch (RetryableAiException $e) { throw $e; }
        catch (Throwable $e) { $creative->update(['processing_status' => 'failed', 'error_message' => 'This creative could not be classified.']); }
        $this->refreshAudit($creative->audit);
    }
    public function failed(?Throwable $e): void
    {
        $creative = AdLibraryAuditCreative::with('audit')->find($this->creativeId);
        if (! $creative) return;
        $creative->update(['processing_status' => 'failed', 'error_message' => 'This creative could not be classified after retrying.']);
        $this->refreshAudit($creative->audit);
    }
    private function refreshAudit(AdLibraryAudit $audit): void
    {
        $counts = $audit->creatives()->selectRaw("SUM(CASE WHEN processing_status = 'complete' THEN 1 ELSE 0 END) completed, SUM(CASE WHEN processing_status = 'failed' THEN 1 ELSE 0 END) failed, COUNT(*) total")->first();
        $audit->update(['progress_total' => (int) $counts->total, 'progress_completed' => (int) $counts->completed, 'progress_failed' => (int) $counts->failed]);
        if ((int) $counts->completed + (int) $counts->failed >= (int) $counts->total) $audit->update(['status' => 'creative_review']);
    }
}
