<?php

namespace App\Jobs;

use App\Models\AlertIncident;
use App\Models\ReportRun;
use App\Services\GoogleChatNotifier;
use App\Services\MetricsQueryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class GenerateReportSnapshotJob implements ShouldQueue
{
    use Queueable;
    public int $timeout = 120;
    public function __construct(public readonly int $reportRunId) {}
    public function handle(MetricsQueryService $metrics, GoogleChatNotifier $chat): void
    {
        $run = ReportRun::with('client')->findOrFail($this->reportRunId);
        try {
            $filters = ['client_id' => $run->client_id, 'client' => $run->client, 'start_date' => $run->period_start->toDateString(), 'end_date' => $run->period_end->toDateString()];
            $snapshot = [
                'client' => $run->client->only(['name', 'slug', 'timezone', 'currency', 'objective', 'goals']),
                'frequency' => $run->frequency, 'period' => ['start' => $filters['start_date'], 'end' => $filters['end_date']],
                'summary' => $metrics->summary($filters), 'trends' => $metrics->trends($filters), 'campaigns' => $metrics->campaigns($filters),
                'alerts' => AlertIncident::query()->where('client_id', $run->client_id)->whereBetween('triggered_at', [$run->period_start->startOfDay(), $run->period_end->endOfDay()])->get(['severity', 'title', 'status', 'triggered_at', 'resolved_at'])->toArray(),
                'generated_at' => now()->toIso8601String(),
            ];
            $run->update(['status' => 'complete', 'snapshot' => $snapshot, 'public_token' => Str::random(64), 'expires_at' => now()->addDays(30), 'generated_at' => now(), 'error_message' => null]);
            $chat->sendReport($run->fresh('client'));
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }
    }
}
