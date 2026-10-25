<?php

namespace App\Services;

use App\Models\AdAccount;
use App\Models\AdLibraryAudit;
use App\Models\AdLibraryRun;
use App\Models\Report;
use App\Models\ReportRun;
use App\Models\WorkflowActivity;
use Illuminate\Support\Str;

class WorkflowActivityService
{
    public function beginMetricCollection(AdAccount $account): WorkflowActivity
    {
        return WorkflowActivity::create([
            'type' => 'metric_collection', 'entity_type' => 'metric_collection', 'entity_id' => (string) Str::uuid(),
            'client_id' => $account->client_id, 'title' => $account->name.' metrics', 'status' => 'pending',
            'stage' => 'pending', 'metadata' => ['platform' => $account->platform->value, 'ad_account_id' => $account->id],
        ]);
    }

    public function finishMetricCollection(WorkflowActivity $activity, ?\Throwable $error = null): void
    {
        $activity->update([
            'status' => $error ? 'failed' : 'complete', 'stage' => $error ? 'failed' : 'collected',
            'error_message' => $error ? $this->safe($error->getMessage()) : null, 'finished_at' => now(),
        ]);
    }

    public function sync(object $entity): ?WorkflowActivity
    {
        $payload = match (true) {
            $entity instanceof Report => $this->deepAudit($entity),
            $entity instanceof ReportRun => $this->performanceReport($entity),
            $entity instanceof AdLibraryRun => $this->adLibrarySearch($entity),
            $entity instanceof AdLibraryAudit => $this->contentAudit($entity),
            default => null,
        };

        if (! $payload) {
            return null;
        }

        return WorkflowActivity::updateOrCreate(
            ['entity_type' => $payload['entity_type'], 'entity_id' => (string) $entity->getKey()],
            $payload
        );
    }

    private function deepAudit(Report $report): array
    {
        return [
            'type' => 'deep_audit', 'entity_type' => Report::class, 'client_id' => $report->client_id,
            'requested_by' => $report->user_id, 'title' => ucfirst((string) $report->platform).' account audit',
            'status' => $report->status ?: 'pending', 'stage' => $report->status ?: 'pending', 'error_message' => $this->safe($report->error_message),
            'started_at' => $report->started_at, 'finished_at' => $report->completed_at,
            'metadata' => ['platform' => $report->platform, 'period' => $report->period],
        ];
    }

    private function performanceReport(ReportRun $run): array
    {
        return [
            'type' => 'report_snapshot', 'entity_type' => ReportRun::class, 'client_id' => $run->client_id,
            'title' => ucfirst((string) $run->frequency).' performance report', 'status' => $run->status ?: 'pending',
            'stage' => $run->status ?: 'pending', 'error_message' => $this->safe($run->error_message),
            'started_at' => $run->created_at, 'finished_at' => $run->generated_at,
            'metadata' => ['period_start' => optional($run->period_start)->toDateString(), 'period_end' => optional($run->period_end)->toDateString()],
        ];
    }

    private function adLibrarySearch(AdLibraryRun $run): array
    {
        $run->loadMissing('search:id,name,company_name,company_type');

        return [
            'type' => 'ad_library_search', 'entity_type' => AdLibraryRun::class, 'requested_by' => $run->requested_by,
            'company_name' => $run->search?->company_name, 'title' => $run->search?->name ?: 'Ad Library search',
            'status' => $run->status ?: 'pending', 'stage' => $run->status ?: 'pending', 'progress_completed' => $run->result_count,
            'progress_total' => $run->reported_total, 'error_message' => $this->safe($run->error_message),
            'started_at' => $run->started_at ?: $run->created_at, 'finished_at' => $run->completed_at,
            'metadata' => ['company_type' => $run->search?->company_type, 'capped' => (bool) $run->capped],
        ];
    }

    private function contentAudit(AdLibraryAudit $audit): array
    {
        return [
            'type' => 'content_audit', 'entity_type' => AdLibraryAudit::class, 'requested_by' => $audit->created_by,
            'company_name' => $audit->company_name, 'title' => $audit->name, 'status' => $audit->status ?: 'draft',
            'stage' => $audit->failed_stage ?: ($audit->status ?: 'draft'), 'progress_completed' => $audit->progress_completed,
            'progress_total' => $audit->progress_total, 'progress_failed' => $audit->progress_failed,
            'error_message' => $this->safe($audit->error_message), 'started_at' => $audit->created_at,
            'finished_at' => $audit->completed_at, 'metadata' => ['provider' => $audit->provider, 'model' => $audit->model, 'media_depth' => $audit->media_depth],
        ];
    }

    private function safe(?string $message): ?string
    {
        if (! $message) {
            return null;
        }

        return mb_substr(preg_replace('/https?:\/\/\S+|Bearer\s+\S+|(key|token|secret)=[^\s&]+/i', '[redacted]', $message) ?: 'Workflow failed.', 0, 1000);
    }
}
