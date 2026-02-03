<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPasswordChange
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    // public function handle(Request $request, Closure $next): Response
    // {
    //     return $next($request);
    // }

    // public function handle(Request $request, Closure $next)
    // {
    //     $user = $request->user();

    //     // Skip check for password change routes
    //     if (
    //         $request->is('api/rater/change-password') ||
    //         $request->is('api/rater/logout')
    //     ) {
    //         return $next($request);
    //     }

    //     // If user must change password, return 403
    //     if ($user && $user->must_change_password) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'You must change your password before continuing',
    //             'must_change_password' => true
    //         ], 403);
    //     }

    //     return $next($request);
    // }

    // App\Http\Middleware\CheckPasswordChange.php
    public function handle(Request $request, Closure $next)
    {
        // If user is authenticated and must change password,
        // only allow the change-password and logout routes
        if ($request->user() && $request->user()->must_change_password) {
            $allowed = [
                'rater/change-password',
                'rater/logout',
            ];

            if (!in_array($request->path(), $allowed)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You must change your password first.',
                    'must_change_password' => true,
                ], 403);
            }
        }

        return $next($request);
    }
}
