<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class QueueHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $workload, string $queue)
    {
        $this->onQueue($queue);
    }

    public function handle(): void
    {
        Cache::put('operations:queue-heartbeat:'.$this->workload, now()->toIso8601String(), now()->addDay());
    }
}
