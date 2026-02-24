<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Mail\EmailApi;
use App\Models\Schedule;
use App\Models\Submission;
use Illuminate\Http\Request;
use App\Models\JobBatchesRsp;
use App\Models\SchedulesApplicant;
use App\Services\ScheduleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class EmailController extends Controller
{
    // set shedules for the applicant wit sent an email


    // store and send email to appllicant interview
    public function storeInterviewApplicant(Request $request, ScheduleService $scheduleService)
    {
        $validated = $request->validate([
            'applicants' => 'required|array',
            'applicants.*.submission_id' => 'required|exists:submission,id',
            'applicants.*.job_batches_rsp' => 'required|exists:job_batches_rsp,id',
            'date_interview' => 'required|date',
            'time_interview' => 'required|string',
            'venue_interview' => 'required|string',
            'batch_name' => 'required|string',
        ]);


        $result = $scheduleService->sendEmailInterview($validated);

        return $result;

    }

    // for the unqualified applicant that send an  the qualification and remarks
    public function applicantUnqualified(Request $request, ScheduleService $scheduleService)
    {
        $validated = $request->validate([
            'job_batches_rsp_id' => 'required|exists:job_batches_rsp,id',
        ]);

        $result = $scheduleService->sendEmailApplicantBatch($validated,$request);


        return $result;
    }

}
