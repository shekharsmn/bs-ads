<?php

namespace App\Console\Commands;

use App\Models\WorkflowAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileStaleWorkflowAttempts extends Command
{
    protected $signature = 'workflows:reconcile-stale';

    protected $description = 'Close workflow attempts that stopped heartbeating';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(config('workloads.attempt_stale_minutes'));
        $count = 0;
        WorkflowAttempt::query()->where('status', 'running')->where('heartbeat_at', '<', $cutoff)
            ->orderBy('id')->chunkById(100, function ($attempts) use (&$count) {
                foreach ($attempts as $attempt) {
                    DB::transaction(function () use ($attempt, &$count) {
                        $locked = WorkflowAttempt::query()->lockForUpdate()->find($attempt->id);
                        if (! $locked || $locked->status !== 'running') {
                            return;
                        }
                        $locked->update(['status' => 'failed', 'stage' => 'stalled', 'error_class' => 'stale_heartbeat', 'error_message' => 'The worker stopped reporting progress.', 'finished_at' => now()]);
                        $locked->activity()->where('attempt_count', $locked->attempt_number)->update(['status' => 'failed', 'stage' => 'stalled', 'error_message' => 'The worker stopped reporting progress.', 'finished_at' => now()]);
                        $count++;
                    });
                }
            });
        $this->info("Reconciled {$count} stale workflow attempt(s).");

        return self::SUCCESS;
    }
}
