<?php

namespace App\Providers;

use App\Models\PortalNotification;
use App\Observers\PortalNotificationObserver;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        PortalNotification::observe(PortalNotificationObserver::class);

        if ($hotFile = env('VITE_HOT_FILE')) {
            Vite::useHotFile(base_path($hotFile));
        }
    }
}
