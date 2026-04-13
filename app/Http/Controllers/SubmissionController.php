<?php

namespace App\Http\Controllers;

use App\Http\Requests\EvaluationRequest;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\excel\nPersonal_info;
use App\Services\SubmissionService;
use Illuminate\Support\Facades\Auth;


class SubmissionController extends Controller
{

    // service for handling the status of the applicant
    protected $submissionService;

    public function __construct(SubmissionService $submissionService)
        {
            $this->submissionService = $submissionService;
        }

    // deleting applicant on the job_post he/she applicant
    public function delete($id)
    {
        $submission = Submission::find($id);

        if (!$submission) {
            return response()->json([
                'status' => false,
                'message' => 'Submission not found.'
            ], 404);
        }

        $submission->delete();
        return response()->json([
            'status' => true,
            'message' => 'Submission deleted successfully.'
        ]);
    }

    // updating the status of the applicant
    public function evaluation(EvaluationRequest $request, $id)
    {
        $validated = $request->validated();

        $result = $this->submissionService->status($validated,$id,$request);

        return $result;
    }


    // getting the image of the internal applicant
    public function getImageInternalApplicant($submissionId){

    $data = $this->submissionService->proxyImage($submissionId);


    return $data;


    }



}
