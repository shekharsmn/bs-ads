<?php

namespace App\Jobs;

use App\Models\AdLibraryAudit;
use App\Services\AdLibraryAudit\AdLibraryAuditWorkflow;
use App\Services\AdLibraryAudit\RetryableAiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateAdLibraryPersonasJob implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 900;
    public function backoff(): array { return [60, 180]; }
    public function __construct(public readonly int $auditId) { $this->onQueue('ad-library-analysis'); }
    public function handle(AdLibraryAuditWorkflow $workflow): void
    {
        $audit = AdLibraryAudit::findOrFail($this->auditId);
        if ($audit->status !== 'generating_personas') return;
        try { $workflow->generatePersonas($audit); }
        catch (RetryableAiException $e) { throw $e; }
        catch (Throwable $e) { $audit->update(['status' => 'failed', 'failed_stage' => 'personas', 'error_message' => 'Persona generation failed.']); }
    }
    public function failed(?Throwable $e): void { AdLibraryAudit::whereKey($this->auditId)->update(['status' => 'failed', 'failed_stage' => 'personas', 'error_message' => 'Persona generation could not be completed.']); }
}
