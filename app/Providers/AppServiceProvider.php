<?php

namespace App\Providers;

use App\Listeners\LogAuthActivity;
use App\Models\User;
use App\Observers\ActivityObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
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
                Limit::perMinutes(5, 5)
                    ->by($request->ip()),
            ];
        });

        RateLimiter::for('resend-verification', function (Request $request) {
            return [
                // ✅ 5 attempts, then locked for 3 minutes.
                Limit::perMinutes(5, 5)
                    ->by($request->ip()),
            ];
        });

        RateLimiter::for('rater-login', function (Request $request) {
            return [
                // ✅ 5 attempts, then locked for 3 minutes.
                Limit::perMinutes(1, 5)  //  Allow 5 attempts per 1 minute
                    ->by($request->ip()),
            ];
        });

        RateLimiter::for('admin-login', function (Request $request) {
            return [
                // ✅ 5 attempts, then locked for 3 minutes.
                Limit::perMinutes(1, 5)  //  Allow 5 attempts per 1 minute
                    ->by($request->ip()),
            ];
        });
    }
}
