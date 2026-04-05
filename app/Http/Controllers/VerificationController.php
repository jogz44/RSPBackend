<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Mail\EmailApi;
use Illuminate\Http\Request;
use App\Models\EmailVerifications;
use App\Services\RecaptchaService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class VerificationController extends Controller
{
    //

    protected $recaptchaService;

    public function __construct(RecaptchaService $recaptchaService)
    {
        $this->recaptchaService = $recaptchaService;
    }

    // send code
    public function sendVerification(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'recaptchaResponse' => 'required',
        ]);

        $result = $this->recaptchaService->code($request);

        return $result;
    }

    // verify the code
    public function verifyCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|numeric',
        ]);

        $result = $this->recaptchaService->verify($request);

        return $result;
    }
}
