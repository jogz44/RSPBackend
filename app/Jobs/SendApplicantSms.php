<?php

namespace App\Jobs;


use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendApplicantSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;
    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $contactNumber,
        protected string $message,
    ) {}

    /**
     * Execute the job.
     */
    // public function handle(): void
    // {
    //     $content = urlencode($this->message);
    //     $number  = preg_replace('/\D/', '', $this->contactNumber);

    //     // ✅ All sensitive values from config — nothing hardcoded
    //     $baseUrl  = config('app.sms_api_url');
    //     $apiUser  = config('app.sms_api_user');
    //     $apiPass  = config('app.sms_api_pass');
    //     $apiPort  = config('app.sms_api_port');

    //     $url = "{$baseUrl}"
    //         . "?1500101=account={$apiUser}"
    //         . "&password={$apiPass}"
    //         . "&port={$apiPort}"
    //         . "&destination={$number}"
    //         . "&content={$content}";

    //     $response = Http::timeout(60)->get($url);

    //     if (!$response->successful()) {
    //         Log::warning('SMS failed', [
    //             'number' => $number,
    //             'status' => $response->status(),
    //             'body'   => $response->body(),
    //         ]);

    //         $this->fail("SMS API returned status: " . $response->status());
    //     }

    //     Log::info('SMS sent', ['number' => $number]);
    // }

    public function handle(): void
{
    $content = urlencode($this->message);
    $number  = preg_replace('/\D/', '', $this->contactNumber);

    $baseUrl = config('app.sms_api_url');
    $apiUser = config('app.sms_api_user');
    $apiPass = config('app.sms_api_pass');
    $port    = rand(1, 4); // spread load across ports

    $url = "{$baseUrl}"
        . "?1500101=account={$apiUser}"
        . "&password={$apiPass}"
        . "&port={$port}"
        . "&destination={$number}"
        . "&content={$content}";

    try {
        $response = Http::timeout(30)->get($url);
    } catch (\Illuminate\Http\Client\ConnectionException $e) {
        Log::warning('SMS gateway timeout, will retry', [
            'number'  => $number,
            'attempt' => $this->attempts(),
            'error'   => $e->getMessage(),
        ]);
        throw $e; // let queue retry handle it
    }

    if (!$response->successful()) {
        Log::warning('SMS failed', [
            'number' => $number,
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        throw new \Exception("SMS API returned status: " . $response->status());
    }

    Log::info('SMS sent', ['number' => $number, 'port' => $port]);
}
}
