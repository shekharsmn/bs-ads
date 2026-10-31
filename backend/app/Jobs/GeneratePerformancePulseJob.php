<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Models\PerformancePulseRun;
use App\Services\PerformancePulseRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class GeneratePerformancePulseJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue(config('workloads.queues.reports', 'reports'));
        $this->tries = config('workloads.policies.reports.tries', 3);
    }

    public function uniqueId(): string
    {
        return 'performance-pulse:'.$this->runId;
    }

    public function handle(PerformancePulseRenderer $renderer): void
    {
        $run = PerformancePulseRun::with(['pulse.client', 'pulse.adAccount', 'pulse.campaign', 'pulse.destinations'])->findOrFail($this->runId);
        if (in_array($run->status, ['queued', 'delivered'], true)) {
            return;
        }

        $run->update(['status' => 'generating', 'error_message' => null]);
        try {
            $rendered = $renderer->render($run->pulse, CarbonImmutable::parse($run->reporting_date, $run->pulse->adAccount->timezone));
            $deliveries = DB::transaction(function () use ($run, $rendered) {
                $run->update([
                    'status' => 'queued', 'rendered_payload' => $rendered['snapshot'], 'warning' => $rendered['warning'],
                    'freshness_at' => $rendered['freshness_at'], 'error_message' => null,
                ]);
                $created = collect();
                $partCount = count($rendered['texts']);
                foreach ($run->pulse->destinations->where('enabled', true) as $destination) {
                    foreach ($rendered['texts'] as $index => $text) {
                        $delivery = NotificationDelivery::firstOrCreate(
                            ['idempotency_key' => 'performance_pulse:'.$run->id.':'.$destination->id.':'.($index + 1)],
                            [
                                'notification_destination_id' => $destination->id, 'performance_pulse_run_id' => $run->id,
                                'event_type' => 'performance_pulse', 'part_number' => $index + 1, 'part_count' => $partCount,
                                'payload' => ['title' => $rendered['title'], 'text' => $text, 'performance_pulse_run_id' => $run->id],
                            ]
                        );
                        if ($delivery->wasRecentlyCreated) {
                            $created->push($delivery);
                        }
                    }
                }

                return $created;
            });
            $deliveries->each(fn (NotificationDelivery $delivery) => SendNotificationJob::dispatch($delivery->id));
            if ($deliveries->isEmpty() && $run->deliveries()->doesntExist()) {
                $run->update(['status' => 'failed', 'error_message' => 'No notification destinations are configured.', 'completed_at' => now()]);
            }
        } catch (\Throwable $error) {
            $run->update(['status' => 'failed', 'error_message' => mb_substr($error->getMessage(), 0, 1000), 'completed_at' => now()]);
            throw $error;
        }
    }
}
