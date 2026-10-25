<?php

namespace App\Providers;

use App\Models\AdLibraryAudit;
use App\Models\AdLibraryRun;
use App\Models\Report;
use App\Models\ReportRun;
use App\Services\AdLibrary\AdLibraryProvider;
use App\Services\AdLibrary\ScraperAdLibraryProvider;
use App\Services\WorkflowActivityService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('public-reports', function (Request $request) {
            $token = (string) $request->route('token');

            return Limit::perMinute(60)->by($request->ip().'|'.hash('sha256', $token));
        });

        foreach ([Report::class, ReportRun::class, AdLibraryRun::class, AdLibraryAudit::class] as $model) {
            $model::saved(fn ($entity) => app(WorkflowActivityService::class)->sync($entity));
        }
    }
}
