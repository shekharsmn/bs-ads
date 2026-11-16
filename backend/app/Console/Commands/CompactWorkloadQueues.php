<?php

namespace App\Console\Commands;

use App\Jobs\CollectAccountMetricsJob;
use App\Jobs\QueueHeartbeatJob;
use App\Services\WorkloadQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class CompactWorkloadQueues extends Command
{
    protected $signature = 'operations:compact-queues {--apply : Apply after all BS Ads queue consumers and producers are paused}';

    protected $description = 'Preview or remove queued heartbeat probes and redundant incremental metrics syncs';

    public function handle(WorkloadQueue $queue): int
    {
        $jobs = $queue->jobs();
        if ($jobs === null || ($this->option('apply') && ! app()->isDownForMaintenance())) {
            $this->error('Requires the database queue and, when applying, maintenance mode.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($jobs) {
            $now = now()->timestamp;
            $retryAfter = (int) config('queue.connections.'.config('queue.default').'.retry_after');
            $query = (clone $jobs)->whereIn('queue', array_values(config('workloads.queues')))
                ->orderByRaw('case when available_at <= ? then 0 else 1 end', [$now])->orderByDesc('id');
            if ($this->option('apply')) {
                $query->lockForUpdate();
            }

            $keep = [];
            $remove = [];
            $counts = [];
            $protected = [];
            foreach ($query->select('id', 'queue', 'payload', 'created_at', 'available_at', 'reserved_at')->cursor() as $row) {
                $name = json_decode($row->payload, true)['displayName'] ?? 'unknown';
                $name = is_string($name) ? $name : 'unknown';
                $key = $row->queue.'|'.$name;
                $counts[$key]['count'] = ($counts[$key]['count'] ?? 0) + 1;
                $counts[$key]['oldest'] = min($counts[$key]['oldest'] ?? $row->created_at, $row->created_at);
                $heartbeat = WorkloadQueue::fields($row->payload, QueueHeartbeatJob::class);
                $probe = $heartbeat && (config('workloads.queues')[$heartbeat['workload']] ?? null) === $row->queue;
                $metrics = WorkloadQueue::fields($row->payload, CollectAccountMetricsJob::class);
                $incremental = $row->queue === config('workloads.queues.collection') && $metrics &&
                    $metrics['startDate'] === null && $metrics['endDate'] === null;
                if (! $probe && ! $incremental) {
                    continue;
                }
                if ($row->reserved_at !== null && $row->reserved_at > $now - $retryAfter) {
                    $protected[] = $row->id;
                    continue;
                }
                if ($incremental && ! isset($keep[$metrics['adAccountId']])) {
                    $keep[$metrics['adAccountId']] = $row->id;
                    continue;
                }
                $remove[] = $row->id;
            }

            $this->line(json_encode([
                'apply' => $this->option('apply'), 'counts' => $counts, 'keep_incremental' => array_values($keep),
                'candidate_ids' => $remove, 'active_reservations' => $protected,
            ], JSON_THROW_ON_ERROR));

            if ($this->option('apply')) {
                if ($protected !== []) {
                    $this->error('Queue compaction aborted while affected reservations are still active.');

                    return self::FAILURE;
                }
                foreach (array_chunk($remove, 500) as $ids) {
                    (clone $jobs)->whereIn('id', $ids)->delete();
                }
            }

            return self::SUCCESS;
        }, 1);
    }
}
