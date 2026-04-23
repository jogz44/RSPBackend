<?php

namespace App\Services;

use App\Mail\EmailApi;
use App\Models\EmailLogs;
use App\Models\EmailVerifications;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RecaptchaService
{
    /**
     * Create a new class instance.
     */
    // public function __construct()
    // {
    //     //
    // }



    //  // send code
    // public function code($request)
    // {


    //     // Skip reCAPTCHA check in local/dev
    //     if (app()->environment('local')) {
    //         $recaptchaData['success'] = true;
    //     } else {
    //         $response = Http::asForm()->post('https://recaptchaenterprise.googleapis.com/v1/projects/sample-firebase-ai-app-d30d2/assessments?key=API_KEY', [
    //             'siteKey' => env('siteKey'),
    //             'token' => $request->input('recaptchaResponse'),
    //         ]);
    //         $recaptchaData = $response->json();
    //     }

    //     if (!($recaptchaData['success'] ?? false)) {
    //         return response()->json(['success' => false, 'message' => 'reCAPTCHA validation failed'], 422);
    //     }

    //     // Continue your normal code...
    //     $code = rand(100000, 999999);

    //     EmailVerifications::updateOrCreate(
    //         ['email' => $request->email],
    //         [
    //             'code' => $code,
    //             'expires_at' => Carbon::now()->addMinutes(2) // code valid for 10 mins
    //         ]
    //     );

    //     // Mail::raw("Your verification code is: $code", function ($message) use ($request) {
    //     //     $message->to($request->email)->subject('Your Verification Code');

    //     $template = 'mail-template.verification';

    //     Mail::to($request->email)->queue((new EmailApi(
    //         "Verification Code",
    //         $template,
    //         [
    //             'code' => $code
    //         ]
    //     ))->onQueue('emails'));

    //     \App\Models\EmailLog::create([
    //         'email' => $request->email,
    //         'activity' => 'Verification Code'
    //     ]);

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Verification code sent successfully!'
    //     ]);
    // }

    // send code
    public function code($request)
    {
        try {

                // Skip reCAPTCHA check in local/dev
                if (app()->environment('local')) {
                    $recaptchaData['success'] = true;
                } else {
                    $response = Http::asForm()->post('https://recaptchaenterprise.googleapis.com/v1/projects/sample-firebase-ai-app-d30d2/assessments?key=API_KEY', [
                        'siteKey' => env('siteKey'),
                        'token' => $request->input('recaptchaResponse'),
                    ]);
                    $recaptchaData = $response->json();
                }

                if (!($recaptchaData['success'] ?? false)) {
                    return response()->json(['success' => false, 'message' => 'reCAPTCHA validation failed'], 422);
                }

                // Continue your normal code...
                $code = rand(100000, 999999);

                EmailVerifications::updateOrCreate(
                    ['email' => $request->email],
                    [
                        'code' => $code,
                        'expires_at' => Carbon::now()->addMinutes(2) // code valid for 10 mins
                    ]
                );

                // Mail::raw("Your verification code is: $code", function ($message) use ($request) {
                //     $message->to($request->email)->subject('Your Verification Code');

                $template = 'mail-template.verification';

                Mail::to($request->email)->queue((new EmailApi(
                    "Verification Code",
                    $template,
                    [
                        'code' => $code
                    ]
                ))->onQueue('emails'));

                \App\Models\EmailLog::create([
                    'email' => $request->email,
                    'activity' => 'Verification Code'
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Verification code sent successfully!'
                ]);
            

        } catch (\Illuminate\Http\Client\RequestException $e) {
            // HTTP request to reCAPTCHA failed
            Log::error('reCAPTCHA request failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'reCAPTCHA service unavailable. Please try again.'], 503);
        } catch (\Exception $e) {
            Log::error('Verification code error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
        }
    }

    public function verify($request)
    {


        // 🔍 Check if record exists for the given email
        $verification = EmailVerifications::where('email', $request->email)->first();

        // ⚠️ Case 1: No record found
        if (!$verification) {
            return response()->json([
                'success' => false,
                'message' => 'No verification request found for this email.'
            ], 404);
        }

        // ⚠️ Case 2: Code does not match
        if ($verification->code != $request->code) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification code.'
            ], 400);
        }

        // ⚠️ Case 3: Code expired
        if (Carbon::now()->greaterThan($verification->expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'Verification code has expired.'
            ], 410); // 410 Gone = resource expired
        }

        // ✅ Case 4: Success
        $verification->delete(); // Delete once verified
        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully!'
        ]);
    }

    // re-send verification code
    public function reSendcode($request)
    {

        // Continue your normal code...
        $code = rand(100000, 999999);

        EmailVerifications::updateOrCreate(
            ['email' => $request->email],
            [
                'code' => $code,
                'expires_at' => Carbon::now()->addMinutes(2) // code valid for 10 mins
            ]
        );

        // Mail::raw("Your verification code is: $code", function ($message) use ($request) {
        //     $message->to($request->email)->subject('Your Verification Code');

        $template = 'mail-template.verification';

        Mail::to($request->email)->queue((new EmailApi(
            "Verification Code",
            $template,
            [
                'code' => $code
            ]
        ))->onQueue('emails'));

        \App\Models\EmailLog::create([
            'email' => $request->email,
            'activity' => 'Resend Verification Code'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Resend Verification code sent successfully!'
        ]);
    }
}
