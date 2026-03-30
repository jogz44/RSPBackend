<?php

namespace App\Providers;

use App\Listeners\LogAuthActivity;
use App\Models\User;
use App\Observers\ActivityObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Request;
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
        RateLimiter::for('send-verification', function (Request $request) {
            return [
                // ✅ 5 attempts, then locked for 3 minutes.
                Limit::perMinutes(3, 5)
                    ->by($request->ip()),

                // // ✅ 20 attempts per hour per IP (stops sustained abuse)
                // Limit::perHour(20)
                //     ->by('hourly:' . $request->ip()),
            ];
        });
    }
}
