<?php

namespace App\Providers;

use App\Models\AdLibraryAudit;
use App\Models\AdLibraryRun;
use App\Models\Report;
use App\Models\ReportRun;
use App\Services\AdLibrary\AdLibraryProvider;
use App\Services\AdLibrary\ScraperAdLibraryProvider;
use App\Services\EmailIntegrationService;
use App\Services\WorkflowActivityService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AdLibraryProvider::class, ScraperAdLibraryProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $worker = getenv('BS_ADS_QUEUE_WORKER');
        if ($this->app->runningInConsole() && is_string($worker) && $worker !== '') {
            Queue::looping(static function () use ($worker) {
                static $lastPoll = 0;
                $now = now()->timestamp;
                if ($now - $lastPoll >= 30 && ! app()->isDownForMaintenance() &&
                    Cache::put('operations:worker-poll:'.$worker, $now, now()->addDay())) {
                    $lastPoll = $now;
                }
            });
        }
        Queue::failing(static function (JobFailed $event) {
            Log::warning('Queue job failed', [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'job_id' => $event->job->getJobId(),
                'exception' => $event->exception::class,
            ]);
        });

        try {
            app(EmailIntegrationService::class)->apply(purge: false);
        } catch (\Throwable) {
            // Database-backed mail settings are optional during setup and migrations.
        }

        RateLimiter::for('public-reports', function (Request $request) {
            $token = (string) $request->route('token');

            return Limit::perMinute(60)->by($request->ip().'|'.hash('sha256', $token));
        });

        foreach ([Report::class, ReportRun::class, AdLibraryRun::class, AdLibraryAudit::class] as $model) {
            $model::saved(fn ($entity) => app(WorkflowActivityService::class)->sync($entity));
        }
    }
}
