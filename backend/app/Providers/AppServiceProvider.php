<?php

namespace App\Providers;

use App\Services\AdLibrary\AdLibraryProvider;
use App\Services\AdLibrary\ScraperAdLibraryProvider;
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
        //
    }
}
