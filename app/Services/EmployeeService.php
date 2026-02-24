<?php

namespace App\Services;

use App\Mail\EmailApi;
use App\Models\JobBatchesRsp;
use App\Models\Submission;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class EmployeeService
{
    /**
     * Create a new class instance.
     */
    // public function __construct()
    // {
    //     //
    // }


    // need to fix to pagination and search optimize make readable
    public function listOfEmployee($request)
    {
        $search  = $request->input('search');
        $perPage = $request->input('per_page', 10);

        $query = DB::table('xPersonal')
            ->select('ControlNo', 'Firstname', 'Surname', 'Occupation');

        // 🔍 Search filter
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('ControlNo', 'like', "%{$search}%")
                    ->orWhere('Firstname', 'like', "%{$search}%")
                    ->orWhere('Surname', 'like', "%{$search}%")
                    ->orWhere('Occupation', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(Firstname,' ',Surname) LIKE ?", ["%{$search}%"]);
            });
        }

        $employees = $query->paginate($perPage);

        return $employees;
    }


    // employee applying  on the job post  storing his application on submission table
    public function employeeApplicant($validated) // employee applicant
    {

        $controlNo = $validated['ControlNo'];
        $jobId     = $validated['job_batches_rsp_id'];

        // ✅ Fetch applicant info
        $applicant = DB::table('xPersonal')
            ->join('xPersonalAddt', 'xPersonalAddt.ControlNo', '=', 'xPersonal.ControlNo')
            ->select(
                'xPersonal.Firstname',
                'xPersonal.Surname',
                'xPersonal.BirthDate',
                'xPersonalAddt.EmailAdd'
            )
            ->where('xPersonal.ControlNo', $controlNo)
            ->first();

        if (!$applicant) {
            return response()->json([
                'message' => 'Applicant not found.'
            ], 404);
        }

        // Convenience variables
        $firstname = $applicant->Firstname;
        $lastname  = $applicant->Surname;
        $birthdate = $applicant->BirthDate;

        $currentJob = JobBatchesRsp::findOrFail($jobId);


        // 2) GET ALL JOB POSTS WITH SAME START/END DATE

        $jobGroupIds = JobBatchesRsp::where('post_date', $currentJob->post_date)
            ->where('end_date', $currentJob->end_date)
            ->pluck('id');


        // 3) COUNT HOW MANY JOBS THE APPLICANT APPLIED WITHIN GROUP

        $applicationCount = DB::table('submission')
            ->join('xPersonal', 'xPersonal.ControlNo', '=', 'submission.ControlNo')
            ->whereIn('submission.job_batches_rsp_id', $jobGroupIds)
            ->where('xPersonal.Firstname', $firstname)
            ->where('xPersonal.Surname', $lastname)
            ->where('xPersonal.BirthDate', $birthdate)
            ->count();

        $post_date = Carbon::parse($currentJob->post_date)->format('F d, Y');
        $end_date   = Carbon::parse($currentJob->end_date)->format('F d, Y');


        if ($applicationCount >= 3) {
            return response()->json([
                'success' => false,
                'message' => "$firstname $lastname, You have already applied for 3 job posts with the same application period (" .
                    $post_date . " to " . $end_date . ")."
            ], 422);
        }

        //  CHECK IF APPLICANT ALREADY APPLIED TO THIS JOB
        $existing = DB::table('submission')
            ->join('xPersonal', 'xPersonal.ControlNo', '=', 'submission.ControlNo')
            ->where('submission.job_batches_rsp_id', $jobId)
            ->where('xPersonal.Firstname', $firstname)
            ->where('xPersonal.Surname', $lastname)
            ->where('xPersonal.BirthDate', $birthdate)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => "$firstname $lastname, you have already applied for this job.",
                'submission_id' => $existing->id ?? null
            ], 409);
        }

        //  CREATE SUBMISSION (NO DUPLICATE FOUND)
        $submit = Submission::create([
            'ControlNo' => $controlNo,
            'status' => 'pending',
            'job_batches_rsp_id' => $jobId,
            'nPersonalInfo_id' => null,
        ]);

        //  SEND EMAIL USING YOUR PRIVATE FUNCTION
        if (!empty($applicant->EmailAdd)) {
            // Format data for private function
            $emailApplicant = (object) [
                'email_address' => $applicant->EmailAdd,
                'firstname'     => $firstname,
                'lastname'      => $lastname
            ];

            $this->sendApplicantEmail($emailApplicant, $jobId, false);
        }

        return response()->json([
            'success' => true,
            'message' => 'Submission created successfully and email sent.',
            'data' => $submit
        ], 201);
    }


    // /**
    //  * Send applicant confirmation or update email.
    //  */
    private function sendApplicantEmail($applicant, $jobId, $isUpdate)
    {
        $job = \App\Models\JobBatchesRsp::findOrFail($jobId);

        $subject = $isUpdate ? 'Application Updated' : 'Application Received';

        $template = 'mail-template.application';


        Mail::to($applicant->email_address)->queue(new EmailApi(
            $subject,
            $template,
            [
                'mailSubject' => $subject,
                'firstname' => $applicant->firstname,
                'lastname' => $applicant->lastname,
                'jobOffice' => $job->Office,
                'jobPosition' => $job->Position,
                'isUpdate' => $isUpdate,
            ]


        ));
    }
}
