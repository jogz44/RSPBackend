<?php

namespace App\Http\Controllers;

use App\Mail\EmailApi;
use App\Models\EmailLog;

use App\Models\JobBatchesRsp;
use App\Models\Schedule;
use App\Models\SchedulesApplicant;
use App\Models\Submission;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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


    // for the unqualified applicant that send an  the qualification and remarks
    public function applicantQualified(Request $request, ScheduleService $scheduleService)
    {
        $validated = $request->validate([
            'job_batches_rsp_id' => 'required|exists:job_batches_rsp,id',
        ]);

        $result = $scheduleService->sendEmailApplicantBatchQualified($validated, $request);


        return $result;
    }

    // tracking email how many already sent
    public function emailTracking()
    {

        $emailsSent = EmailLog::whereDate('created_at', today())->count();

        if ($emailsSent === 0) {
            return response()->json([
                'message' => 'No emails sent today',
                'emails_sent_today' => 0,
                'emails_remaining' => 500
            ]);
        }

        $emailsRemaining = max(500 - $emailsSent, 0);

        return response()->json([
            'emails_sent_today' => $emailsSent,
            'emails_remaining' => $emailsRemaining
        ]);
    }


    // store and send email to appllicant interview
    public function storeExaminationApplicant(Request $request, ScheduleService $scheduleService)
    {
        $validated = $request->validate([
            'applicants' => 'required|array',
            'applicants.*.submission_id' => 'required|exists:submission,id',
            'applicants.*.job_batches_rsp' => 'required|exists:job_batches_rsp,id',
            'date_exam' => 'required|date',
            'time_exam' => 'required|string',
            'venue_exam' => 'required|string',
            'batch_name' => 'required|string',
        ]);


        $result = $scheduleService->sendEmailExamination($validated);

        return $result;
    }

}
