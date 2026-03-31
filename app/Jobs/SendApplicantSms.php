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
    public function handle(): void
    {
        $content = urlencode($this->message);
        $number  = preg_replace('/\D/', '', $this->contactNumber); // strip non-digits

        $url = "http://192.168.100.52/cgi/WebCGI"
            . "?1500101=account=apiuser"
            . "&password=apipass"
            . "&port=1"
            . "&destination={$number}"
            . "&content={$content}";

        $response = Http::timeout(15)->get($url);

        if (!$response->successful()) {
            Log::warning('SMS failed', [
                'number'   => $number,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);

            // Will be retried up to $tries times
            $this->fail("SMS API returned status: " . $response->status());
        }

        Log::info('SMS sent', ['number' => $number]);
    }
}
