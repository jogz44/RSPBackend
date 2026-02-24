<?php

namespace App\Http\Controllers;

use App\Models\Submission;
use Illuminate\Http\Request;
use App\Models\JobBatchesRsp;
use App\Services\ApplicantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ExportApplicantController extends Controller
{
    /**
     * Fetch all applicants (internal + external) from the full job post history
     */
    public function fetchApplicantAppliedOldJobPost($job_post_id, ApplicantService $applicantService)
    {
        $result = $applicantService->applicant($job_post_id);

        return  $result;
    }

    // export the applicant on the old job post to the new post job 
    public function exportApplicant(Request $request, ApplicantService $applicantService)
    {
        /**
         * job_batches_rsp_id => Job Post ID
         * applicants => array that can contain either:
         *    { "id": <nPersonalInfo_id> } OR { "ControlNo": <ControlNo> }
         */

        $validated = $request->validate([
            'job_batches_rsp_id' => 'required|integer|exists:job_batches_rsp,id',
            'applicants' => 'required|array|min:1',
            'applicants.*.id' => 'nullable|exists:nPersonalInfo,id',
            'applicants.*.ControlNo' => 'nullable|exists:xPersonal,ControlNo',
        ]);



        $result = $applicantService->store($validated,$request);

        return $result;


    }
}
