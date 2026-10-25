<?php

namespace App\Console\Commands;

use App\Jobs\GenerateReportSnapshotJob;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Services\MethodologyRegistry;
use Illuminate\Console\Command;

class DispatchDueReports extends Command
{
    protected $signature = 'reports:dispatch-due';

    protected $description = 'Generate weekly and monthly reports due in each client timezone';

    public function handle(MethodologyRegistry $methodologies): int
    {
        ReportSchedule::with('client')->where('enabled', true)->orderBy('id')->chunkById(100, function ($schedules) use ($methodologies) {
            $schedules->each(function (ReportSchedule $schedule) use ($methodologies) {
                $now = now($schedule->client->timezone);
                if ($now->format('H:i') !== substr($schedule->local_time, 0, 5)) {
                    return;
                }
                $due = $schedule->frequency === 'weekly' ? $now->dayOfWeek === (int) $schedule->weekday : $now->day === (int) $schedule->day_of_month;
                if (! $due) {
                    return;
                }
                $end = $now->copy()->subDay()->endOfDay();
                $start = $schedule->frequency === 'weekly' ? $end->copy()->subDays(6)->startOfDay() : $end->copy()->startOfMonth();
                $identity = [
                    'client_id' => $schedule->client_id, 'report_schedule_id' => $schedule->id,
                    'report_template_id' => $schedule->report_template_id,
                    'frequency' => $schedule->frequency, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                ];
                $run = ReportRun::firstOrCreate($identity, ['status' => 'pending', ...$methodologies->columns('performance-report')]);
                if ($run->wasRecentlyCreated) {
                    GenerateReportSnapshotJob::dispatch($run->id);
                }
            });
        });

        return self::SUCCESS;
    }
}
