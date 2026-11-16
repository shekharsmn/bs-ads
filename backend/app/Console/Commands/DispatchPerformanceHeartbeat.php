<?php

namespace App\Console\Commands;

use App\Services\WorkloadQueue;
use App\Models\AdAccount;
use Illuminate\Console\Command;

class DispatchPerformanceHeartbeat extends Command
{
    protected $signature = 'performance:heartbeat {--sync : Run jobs synchronously}';

    protected $description = 'Dispatch lightweight metric collection for every active ad account';

    public function handle(WorkloadQueue $queue): int
    {
        $count = 0;
        AdAccount::query()->where('status', 'active')->whereHas('client', fn ($q) => $q->where('active', true))->orderBy('id')->chunkById(100, function ($accounts) use (&$count, $queue) {
            foreach ($accounts as $account) {
                $count += (int) $queue->collection($account->id, sync: $this->option('sync'));
            }
        });
        $this->info("Dispatched $count account collection job(s).");

        return self::SUCCESS;
    }
}
