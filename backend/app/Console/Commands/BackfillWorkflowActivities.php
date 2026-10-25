<?php

namespace App\Console\Commands;

use App\Models\AdLibraryAudit;
use App\Models\AdLibraryRun;
use App\Models\Report;
use App\Models\ReportRun;
use App\Services\WorkflowActivityService;
use Illuminate\Console\Command;

class BackfillWorkflowActivities extends Command
{
    protected $signature = 'activities:backfill {--chunk=200}';

    protected $description = 'Idempotently backfill the permanent workflow activity history.';

    public function handle(WorkflowActivityService $activities): int
    {
        $total = 0;
        foreach ([Report::class, ReportRun::class, AdLibraryRun::class, AdLibraryAudit::class] as $model) {
            $model::query()->chunkById((int) $this->option('chunk'), function ($records) use ($activities, &$total) {
                foreach ($records as $record) {
                    $activities->sync($record);
                    $total++;
                }
            });
        }
        $this->info("Synced {$total} workflow records.");

        return self::SUCCESS;
    }
}
