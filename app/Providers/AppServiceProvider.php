<?php

namespace App\Providers;

use App\Listeners\LogAuthActivity;
use App\Models\User;
use App\Observers\ActivityObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

        // ✅ For public API routes (outside sanctum)
        RateLimiter::for('public-api', function (Request $request) {
            return[Limit::perMinute(30) // 30 requests per minute
                ->by($request->ip()),  // limit per IP address
            ];
        });


        // application
        RateLimiter::for('application', function (Request $request) {
            return [
                // ✅ 5 attempts, then locked for 3 minutes.
                Limit::perMinutes(1, 15)  //  Allow 5 attempts per 1 minute
                    ->by($request->ip()),
            ];
        });

        $this->connectNetworkShare();

    }

    // ✅ Clean separate method
    private function connectNetworkShare(): void
    {
        $host     = config('app.network_share.host');
        $name     = config('app.network_share.name');
        $username = config('app.network_share.username');
        $password = config('app.network_share.password');

        if (!$host || !$name || !$username || !$password) {
            Log::warning('Network share config is incomplete, skipping connection.');
            return;
        }

        $sharePath = "\\\\{$host}\\{$name}";

        // Check if already connected
        exec("net use \"{$sharePath}\" 2>&1", $output, $code);

        if ($code !== 0) {
            // Not connected, connect now
            exec(
                "net use \"{$sharePath}\" \"{$password}\" /user:\"{$username}\" /persistent:yes 2>&1",
                $output,
                $code
            );

            if ($code !== 0) {
                Log::error('Network share connection failed: ' . implode(' ', $output));
            } else {
                Log::info("Network share connected: {$sharePath}");
            }
        } else {
            Log::info("Network share already connected: {$sharePath}");
        }
    }
}
