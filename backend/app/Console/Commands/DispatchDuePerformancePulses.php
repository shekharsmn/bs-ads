<?php

namespace App\Console\Commands;

use App\Jobs\GeneratePerformancePulseJob;
use App\Models\PerformancePulse;
use App\Models\PerformancePulseRun;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DispatchDuePerformancePulses extends Command
{
    protected $signature = 'performance-pulses:dispatch-due';

    protected $description = 'Dispatch daily Performance Pulses due in each ad account timezone';

    public function handle(): int
    {
        PerformancePulse::with('adAccount')->where('enabled', true)->orderBy('id')->chunkById(100, function ($pulses) {
            foreach ($pulses as $pulse) {
                $now = now($pulse->adAccount->timezone);
                if ($now->format('H:i') !== substr($pulse->local_time, 0, 5)) {
                    continue;
                }
                $reportingDate = $now->copy()->subDay()->toDateString();
                $scheduleKey = 'performance-pulse:'.$pulse->id.':'.$now->toDateString();
                $run = PerformancePulseRun::firstOrCreate(
                    ['schedule_key' => $scheduleKey],
                    ['performance_pulse_id' => $pulse->id, 'trigger' => 'scheduled', 'reporting_date' => $reportingDate, 'idempotency_key' => (string) Str::uuid(), 'status' => 'pending']
                );
                if ($run->wasRecentlyCreated) {
                    GeneratePerformancePulseJob::dispatch($run->id);
                }
            }
        });

        return self::SUCCESS;
    }
}
