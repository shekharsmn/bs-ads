<?php

namespace App\Console\Commands;

use App\Jobs\GenerateReportSnapshotJob;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use Illuminate\Console\Command;

class DispatchDueReports extends Command
{
    protected $signature = 'reports:dispatch-due';
    protected $description = 'Generate weekly and monthly reports due in each client timezone';
    public function handle(): int
    {
        ReportSchedule::with('client')->where('enabled', true)->get()->each(function (ReportSchedule $schedule) {
            $now = now($schedule->client->timezone);
            if ($now->format('H:i') !== substr($schedule->local_time, 0, 5)) return;
            $due = $schedule->frequency === 'weekly' ? $now->dayOfWeek === (int) $schedule->weekday : $now->day === (int) $schedule->day_of_month;
            if (! $due) return;
            $end = $now->copy()->subDay()->endOfDay();
            $start = $schedule->frequency === 'weekly' ? $end->copy()->subDays(6)->startOfDay() : $end->copy()->startOfMonth();
            $run = ReportRun::firstOrCreate([
                'client_id' => $schedule->client_id, 'report_schedule_id' => $schedule->id,
                'frequency' => $schedule->frequency, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
            ]);
            if ($run->wasRecentlyCreated) GenerateReportSnapshotJob::dispatch($run->id);
        });
        return self::SUCCESS;
    }
}
