<?php

namespace App\Console\Commands;

use App\Models\MetricSnapshot;
use Illuminate\Console\Command;

class PruneMetricHistory extends Command
{
    protected $signature = 'performance:prune-history';
    protected $description = 'Remove normalized metric rows older than 24 months';
    public function handle(): int
    {
        $deleted = MetricSnapshot::whereDate('metric_date', '<', now()->subMonthsNoOverflow(24)->toDateString())->delete();
        $this->info("Deleted $deleted expired metric row(s).");
        return self::SUCCESS;
    }
}
