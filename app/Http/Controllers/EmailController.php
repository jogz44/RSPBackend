<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExaminationApplicantStoreRequest;
use App\Http\Requests\ExaminationApplicantUpdateRequest;
use App\Http\Requests\InterviewApplicantStoreRequest;
use App\Http\Requests\InterviewApplicantUpdateRequest;
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
    public function storeInterviewApplicant( ScheduleService $scheduleService,InterviewApplicantStoreRequest $interviewApplicantStoreRequest)
    {
        $validated = $interviewApplicantStoreRequest->validated();

        $result = $scheduleService->sendEmailInterview($validated);

        return $result;
    }

    // update and send email to appllicant interview
    public function updateInterviewApplicant(InterviewApplicantUpdateRequest $interviewApplicantUpdateRequest, ScheduleService $scheduleService, $scheduleId)
    {

        $validated = $interviewApplicantUpdateRequest->validated();

        $result = $scheduleService->updateEmailInterview($validated, $scheduleId);

        return $result;
    }

    // cancel and send email to appllicant interview
    public function cancelInterviewApplicant($scheduleId , ScheduleService $scheduleService)
    {

        $result = $scheduleService->cancelEmailInterview($scheduleId);

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
    public function storeExaminationApplicant(ExaminationApplicantStoreRequest $examinationApplicantStoreRequest, ScheduleService $scheduleService)
    {
        $validated = $examinationApplicantStoreRequest->validated();


        $result = $scheduleService->sendEmailExamination($validated);

        return $result;
    }

    // update and send email to appllicant examination
    public function updateExaminationApplicant(ExaminationApplicantUpdateRequest $examinationApplicantUpdateRequest, ScheduleService $scheduleService, $scheduleExamId)
    {
        $validated = $examinationApplicantUpdateRequest->validated();


        $result = $scheduleService->updateEmailExamination($validated,$scheduleExamId);

        return $result;
    }

    //  cancel and send email to appllicant examination
    public function cancelExaminationApplicant($scheduleExamId, ScheduleService $scheduleService)
    {

        $result = $scheduleService->cancelEmailExamination($scheduleExamId);

        return $result;
    }
}
