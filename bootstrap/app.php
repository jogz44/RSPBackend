<?php


use App\Http\Middleware\Cors;
use Fruitcake\Cors\HandleCors;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\LogActivity;
use Illuminate\Foundation\Application;
use App\Http\Middleware\CheckPasswordChange;
use App\Http\Middleware\ActivityLoggerMiddleware;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
    // $middleware->prepend(Cors::class);
    // $middleware->cors(); // ✅ This enables Laravel’s built-in CORS

    // $middleware->append(\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class);
    // $middleware->alias([
    //     // 'role' => CheckRole::class,
    // ]);
    // $middleware->alias([
    //     'password.change' => CheckPasswordChange::class,
    // ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {

        // ✅ Friendly rate limit error response
        $exceptions->render(function (ThrottleRequestsException $exception) {
            $retryAfter = $exception->getHeaders()['Retry-After'] ?? 60;
            $minutes    = ceil($retryAfter / 60);

            return response()->json([
                'success'     => false,
                'message'     => "Too many attempts. Please wait {$minutes} minute(s) before trying again.",
                'retry_after' => $retryAfter . ' seconds',
                'reset_at'    => now()->addSeconds($retryAfter)->toDateTimeString(),
                'locked_out'  => true,
            ], 429);
        });
    })->create();
