<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use Illuminate\Console\Command;

class PruneAuditEvents extends Command
{
    protected $signature = 'governance:prune-audit-events {--months=24}';

    protected $description = 'Remove audit events older than the configured operational retention window';

    public function handle(): int
    {
        AuditEvent::where('created_at', '<', now()->subMonths(max(1, (int) $this->option('months'))))->delete();

        return self::SUCCESS;
    }
}
