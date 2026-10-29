<?php

namespace App\Jobs;

use App\Models\AlertIncident;
use App\Models\ReportRun;
use App\Services\GoogleChatNotifier;
use App\Services\MetricsQueryService;
use App\Services\NotificationDispatcher;
use App\Services\PerformanceCollectionService;
use App\Services\WorkflowActivityService;
use App\Services\WorkflowCoordinator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class GenerateReportSnapshotJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public function __construct(public readonly int $reportRunId)
    {
        $this->onQueue(config('workloads.queues.reports', 'reports'));
        $this->timeout = max(
            config('workloads.policies.reports.timeout', 120),
            config('workloads.policies.collection.timeout', 300),
        );
        $this->tries = config('workloads.policies.reports.tries', 3);
    }

    public function uniqueId(): string
    {
        return 'performance-report:'.$this->reportRunId;
    }

    public function handle(MetricsQueryService $metrics, GoogleChatNotifier $chat, NotificationDispatcher $notifications, WorkflowActivityService $activities, WorkflowCoordinator $workflows, PerformanceCollectionService $collector): void
    {
        $run = ReportRun::with(['client', 'adAccount', 'template'])->findOrFail($this->reportRunId);
        $attempt = $workflows->start($activities->sync($run), 'generating_snapshot', 'report:'.$run->id.':'.($this->job?->uuid() ?? Str::uuid()));
        try {
            $run->update(['status' => 'running', 'error_message' => null]);
            $uncollectedAccounts = $run->client->adAccounts()
                ->with(['connection', 'client'])
                ->when($run->ad_account_id, fn ($query, $id) => $query->whereKey($id))
                ->where('status', 'active')
                ->whereNull('last_collected_at')
                ->get();
            foreach ($uncollectedAccounts as $account) {
                $collector->collect($account, $run->period_start->toDateString(), $run->period_end->toDateString());
            }
            $filters = ['client_id' => $run->client_id, 'ad_account_id' => $run->ad_account_id, 'client' => $run->client, 'start_date' => $run->period_start->toDateString(), 'end_date' => $run->period_end->toDateString()];
            $periodDays = $run->period_start->diffInDays($run->period_end) + 1;
            $previousEnd = $run->period_start->copy()->subDay();
            $previousFilters = ['client_id' => $run->client_id, 'ad_account_id' => $run->ad_account_id, 'client' => $run->client, 'start_date' => $previousEnd->copy()->subDays($periodDays - 1)->toDateString(), 'end_date' => $previousEnd->toDateString()];
            $template = $run->template;
            $sections = $template?->sections ?: ['summary', 'trends', 'campaigns', 'alerts'];
            $snapshot = [
                'client' => $run->client->only(['name', 'slug', 'timezone', 'currency', 'objective', 'goals']),
                'ad_account' => $run->adAccount?->only(['id', 'name', 'external_id', 'platform', 'currency', 'timezone']),
                'frequency' => $run->frequency, 'period' => ['start' => $filters['start_date'], 'end' => $filters['end_date']],
                'summary' => in_array('summary', $sections, true) ? $metrics->summary($filters) : null,
                'comparison' => in_array('summary', $sections, true) ? ['label' => 'Previous period', 'summary' => $metrics->summary($previousFilters)] : null,
                'trends' => in_array('trends', $sections, true) ? $metrics->trends($filters) : null,
                'campaigns' => in_array('campaigns', $sections, true) ? $metrics->campaigns($filters) : null,
                'alerts' => in_array('alerts', $sections, true) ? AlertIncident::query()->where('client_id', $run->client_id)->when($run->ad_account_id, fn ($query, $id) => $query->where('ad_account_id', $id))->whereBetween('triggered_at', [$run->period_start->startOfDay(), $run->period_end->endOfDay()])->get(['severity', 'title', 'status', 'triggered_at', 'resolved_at'])->toArray() : null,
                'template' => ['name' => $template?->name, 'sections' => $sections, 'brand_name' => $template?->brand_name, 'brand_color' => $template?->brand_color, 'logo_url' => $template?->logo_url],
                'generated_at' => now()->toIso8601String(),
            ];
            $workflows->heartbeat($attempt, 'publishing_snapshot');
            $run->update(['status' => 'complete', 'delivery_status' => 'sending', 'snapshot' => $snapshot, 'template_snapshot' => $snapshot['template'], 'public_token' => Str::random(64), 'expires_at' => now()->addDays(30), 'generated_at' => now(), 'error_message' => null]);
            try {
                $count = $notifications->dispatch($run->client_id, 'report_delivery', ['title' => 'Performance report ready', 'text' => ucfirst($run->frequency)." performance report for {$run->client->name} is ready.", 'report_run_id' => $run->id], (string) $run->id);
                $legacyDelivered = $count === 0 ? $chat->sendReport($run->fresh('client')) : false;
                $run->update(['delivery_status' => $count > 0 ? 'queued' : ($legacyDelivered ? 'delivered' : 'not_configured'), 'delivered_at' => $legacyDelivered ? now() : null, 'delivery_error' => null]);
            } catch (\Throwable $deliveryError) {
                $run->update(['delivery_status' => 'failed', 'delivery_error' => mb_substr($deliveryError->getMessage(), 0, 1000)]);
            }
            $workflows->complete($attempt, 'complete');
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            $workflows->fail($attempt, $e, 'snapshot_failed');
            throw $e;
        }
    }
}
