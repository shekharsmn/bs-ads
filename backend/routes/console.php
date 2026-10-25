<?php

use App\Jobs\QueueHeartbeatJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    Cache::put('operations:scheduler-heartbeat', now()->toIso8601String(), now()->addDay());
    foreach (config('workloads.queues', []) as $workload => $queue) {
        QueueHeartbeatJob::dispatch($workload, $queue);
    }
})->name('operations-heartbeats')->everyMinute()->onOneServer()->withoutOverlapping(2);

Schedule::command('workflows:reconcile-stale')->everyFiveMinutes()->onOneServer()->withoutOverlapping(4);

Schedule::command('performance:heartbeat')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(55);

Schedule::command('reports:dispatch-due')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(5);

Schedule::command('performance:prune-history')
    ->dailyAt('02:30')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('performance:monitor-quality')
    ->hourlyAt(20)
    ->onOneServer()
    ->withoutOverlapping(55);

Schedule::command('governance:prune-audit-events --months=24')
    ->monthlyOn(1, '03:15')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('ai:monitor-budgets')
    ->hourlyAt(35)
    ->onOneServer()
    ->withoutOverlapping(55);
