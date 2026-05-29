<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddExamScoreApplicantRequest;
use App\Http\Requests\UpdateExamScoreApplicantRequest;
use App\Models\ApplicantExamScore;
use App\Models\Submission;
use App\Services\ActivityLogService;
use App\Services\ApplicantExamScoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;


class ApplicantExamScoreController extends Controller
{
    //

    protected $applicantExamScoreService;
    protected $activityLogService;

    public function __construct(ApplicantExamScoreService $applicantExamScoreService, ActivityLogService $activityLogService)
    {
        $this->applicantExamScoreService = $applicantExamScoreService;
        $this->activityLogService = $activityLogService;
    }

    // store the applicant exam score
    public function applicantExamScoreStore(AddExamScoreApplicantRequest $request)
    {
        $validated = $request->validated();

        return $this->applicantExamScoreService->addExamScoreOfApplicant($validated);
    }


    // update the applicant exam score
    public function applicantExamScoreUpdate(UpdateExamScoreApplicantRequest $request, $submissionId)
    {
        $validated = $request->validated();

        return $this->applicantExamScoreService->updateExamScoreOfApplicant($validated, $submissionId);
    }

    // delete the applicant exam score
    public function applicantExamScoreDelete($applicantExamScoreId)
    {
        $exam = ApplicantExamScore::find($applicantExamScoreId);

        if (!$exam) {
            return response()->json([
                'status'  => false,
                'message' => 'Exam score Id not found.',
            ], 404);
        }
        // user
        $user = Auth::user();

        $exam->delete();
        //  Log after delete, using pre-captured $exam data
        $this->activityLogService->logApplicantExamScoreDelete($user, $exam);

        return response()->json([
            'status'  => true,
            'message' => 'Applicant exam score deleted successfully.',
        ], 200);
    }

    // list of applicant that dont have yet exam score
    public function listOfApplicantWithOutExamScore(Request $request)
    {
        return $this->applicantExamScoreService->applicantDontHaveExamScore($request);
    }


    // list of applicant that have exam score
    public function listOfApplicantWithScore(Request $request)
    {
        return $this->applicantExamScoreService->listOfApplicantWithScore($request);
    }

}
