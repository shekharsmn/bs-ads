<?php

namespace App\Console\Commands;

use App\Models\AdAccount;
use App\Services\DataQualityMonitor;
use Illuminate\Console\Command;

class MonitorDataQuality extends Command
{
    protected $signature = 'performance:monitor-quality';

    protected $description = 'Detect and reconcile data-quality issues for active ad accounts';

    public function handle(DataQualityMonitor $monitor): int
    {
        AdAccount::with('client')->where('status', '!=', 'archived')->orderBy('id')->chunkById(100, fn ($accounts) => $accounts->each(fn ($account) => $monitor->inspect($account)));

        return self::SUCCESS;
    }
}
