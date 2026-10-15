<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
