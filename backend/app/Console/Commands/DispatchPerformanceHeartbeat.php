<?php

namespace App\Console\Commands;

use App\Jobs\CollectAccountMetricsJob;
use App\Models\AdAccount;
use Illuminate\Console\Command;

class DispatchPerformanceHeartbeat extends Command
{
    protected $signature = 'performance:heartbeat {--sync : Run jobs synchronously}';

    protected $description = 'Dispatch lightweight metric collection for every active ad account';

    public function handle(): int
    {
        $count = 0;
        AdAccount::query()->where('status', 'active')->whereHas('client', fn ($q) => $q->where('active', true))->orderBy('id')->chunkById(100, function ($accounts) use (&$count) {
            foreach ($accounts as $account) {
                $this->option('sync') ? CollectAccountMetricsJob::dispatchSync($account->id) : CollectAccountMetricsJob::dispatch($account->id);
                $count++;
            }
        });
        $this->info("Dispatched $count account collection job(s).");

        return self::SUCCESS;
    }
}
