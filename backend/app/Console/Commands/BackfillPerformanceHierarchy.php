<?php

namespace App\Console\Commands;

use App\Services\WorkloadQueue;
use App\Models\AdAccount;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class BackfillPerformanceHierarchy extends Command
{
    protected $signature = 'performance:backfill-hierarchy {--days=90 : Number of completed local days to collect} {--sync : Run jobs synchronously}';

    protected $description = 'Backfill campaign, ad group/ad set, and ad performance for active accounts';

    public function handle(WorkloadQueue $queue): int
    {
        $days = max(1, min(365, (int) $this->option('days')));
        $count = 0;
        AdAccount::query()->where('status', 'active')->whereHas('client', fn ($query) => $query->where('active', true))
            ->orderBy('id')->chunkById(100, function ($accounts) use ($days, &$count, $queue) {
                foreach ($accounts as $account) {
                    $end = CarbonImmutable::now($account->timezone)->subDay()->toDateString();
                    $start = CarbonImmutable::parse($end, $account->timezone)->subDays($days - 1)->toDateString();
                    $count += (int) $queue->collection($account->id, $start, $end, sync: $this->option('sync'));
                }
            });
        $this->info("Dispatched {$count} hierarchy backfill job(s) for {$days} completed day(s).");

        return self::SUCCESS;
    }
}
