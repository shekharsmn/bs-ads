<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeAdLibraryCreativeJob;
use App\Jobs\GenerateReportSnapshotJob;
use App\Jobs\RunAdLibraryScrapeJob;
use App\Models\AdLibraryAudit;
use App\Models\AdLibraryRun;
use App\Models\Report;
use App\Models\ReportRun;
use App\Models\WorkflowActivity;
use App\Services\ClientAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\Rule;

class WorkflowActivityController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'client_id' => 'nullable|integer|exists:clients,id', 'type' => 'nullable|string|max:40',
            'status' => 'nullable|string|max:32', 'per_page' => 'nullable|integer|min:10|max:100',
        ]);
        $clientIds = ClientAccess::clientsFor($request->user())->pluck('id');

        return WorkflowActivity::query()
            ->where(fn ($q) => $q->whereNull('client_id')->orWhereIn('client_id', $clientIds))
            ->when($data['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($data['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with(['client:id,name', 'requester:id,name', 'latestAttempt'])->latest('updated_at')->paginate($data['per_page'] ?? 25);
    }

    public function show(Request $request, WorkflowActivity $activity): WorkflowActivity
    {
        $this->authorizeActivity($request, $activity);

        return $activity->load(['client:id,name', 'requester:id,name', 'latestAttempt']);
    }

    public function attempts(Request $request, WorkflowActivity $activity)
    {
        $this->authorizeActivity($request, $activity);
        $data = $request->validate(['per_page' => 'nullable|integer|min:10|max:100']);

        return $activity->attempts()->paginate($data['per_page'] ?? 25);
    }

    public function action(Request $request, WorkflowActivity $activity)
    {
        abort_unless($request->user()->canManage(), 403);
        $this->authorizeActivity($request, $activity);
        $data = $request->validate(['action' => ['required', Rule::in(['pause', 'resume', 'cancel', 'retry'])]]);

        match ($activity->entity_type) {
            AdLibraryAudit::class => $this->auditAction($activity, $data['action']),
            Report::class => $this->deepAuditAction($activity, $data['action']),
            ReportRun::class => $this->reportAction($activity, $data['action']),
            AdLibraryRun::class => $this->searchAction($activity, $data['action'], $request),
            default => abort(422, 'This activity cannot be controlled.'),
        };

        return response()->json(['message' => 'Activity action accepted.'], 202);
    }

    private function auditAction(WorkflowActivity $activity, string $action): void
    {
        $audit = AdLibraryAudit::findOrFail($activity->entity_id);
        if ($action === 'pause') {
            abort_unless($audit->status === 'analyzing_creatives', 409);
            $audit->update(['status' => 'creative_paused']);

            return;
        }
        abort_unless(in_array($action, ['resume', 'retry'], true), 422);
        abort_unless(
            ($action === 'resume' && $audit->status === 'creative_paused')
            || ($action === 'retry' && $audit->status === 'creative_review'),
            409
        );
        $query = $audit->creatives()->where('processing_status', $action === 'retry' ? 'failed' : 'pending');
        $ids = $query->pluck('id');
        abort_if($ids->isEmpty(), 422, 'There is no pending work for this action.');
        if ($action === 'retry') {
            $audit->creatives()->whereIn('id', $ids)->update(['processing_status' => 'pending', 'error_message' => null]);
        }
        $audit->update(['status' => 'analyzing_creatives']);
        Bus::batch($ids->map(fn ($id) => new AnalyzeAdLibraryCreativeJob($id))->all())->name("Activity audit {$audit->id}")->onQueue(config('workloads.queues.ad_library_analysis', 'ad-library-analysis'))->allowFailures()->dispatch();
    }

    private function deepAuditAction(WorkflowActivity $activity, string $action): void
    {
        abort_unless($action === 'cancel', 422);
        $report = Report::findOrFail($activity->entity_id);
        abort_unless(in_array($report->status, ['pending', 'running'], true), 409);
        $report->update(['cancel_requested_at' => now()]);
    }

    private function reportAction(WorkflowActivity $activity, string $action): void
    {
        abort_unless($action === 'retry', 422);
        $run = ReportRun::findOrFail($activity->entity_id);
        abort_unless($run->status === 'failed', 409);
        $run->update(['status' => 'pending', 'delivery_status' => 'pending', 'error_message' => null, 'delivery_error' => null]);
        GenerateReportSnapshotJob::dispatch($run->id);
    }

    private function searchAction(WorkflowActivity $activity, string $action, Request $request): void
    {
        abort_unless($action === 'retry', 422);
        $run = AdLibraryRun::with('search')->findOrFail($activity->entity_id);
        abort_unless(in_array($run->status, ['failed', 'blocked', 'empty', 'partial', 'complete'], true), 409);
        $newRun = $run->search->runs()->create(['requested_by' => $request->user()->id, 'provider' => $run->provider, 'status' => 'pending']);
        RunAdLibraryScrapeJob::dispatch($newRun->id);
    }

    private function authorizeActivity(Request $request, WorkflowActivity $activity): void
    {
        if ($activity->client_id) {
            abort_unless(ClientAccess::clientsFor($request->user())->whereKey($activity->client_id)->exists(), 403);
        }
    }
}
