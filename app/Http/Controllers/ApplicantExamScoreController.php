<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddExamScoreApplicantRequest;
use App\Http\Requests\UpdateExamScoreApplicantRequest;
use App\Models\ApplicantExamScore;
use App\Models\Submission;
use App\Services\ApplicantExamScoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;


class ApplicantExamScoreController extends Controller
{
    //

    protected $applicantExamScoreService;

    public function __construct(ApplicantExamScoreService $applicantExamScoreService)
    {
        $this->applicantExamScoreService = $applicantExamScoreService;
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

        $exam->delete();

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

    public function getApplicantPhoto($nPersonalInfoId)
    {
        $personalInfo = \App\Models\excel\nPersonal_info::find($nPersonalInfoId);
        $rawImagePath = $personalInfo->image_path ?? null;

        if (!$rawImagePath) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        try {
            // Case 1: MinIO or any HTTP URL — fetch server-side (no CORS issue)
            if (filter_var($rawImagePath, FILTER_VALIDATE_URL)) {
                $fileContents = file_get_contents($rawImagePath);

                if ($fileContents === false) {
                    return response()->json(['error' => 'Failed to fetch image'], 404);
                }

                // Detect mime type from content
                $finfo    = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($fileContents) ?: 'image/jpeg';

                return response($fileContents, 200)
                    ->header('Content-Type', $mimeType)
                    ->header('Cache-Control', 'public, max-age=3600');
            }

            // Case 2: Local storage path
            if (Storage::disk('public')->exists($rawImagePath)) {
                $fullPath = storage_path('app/public/' . $rawImagePath);
                $mimeType = mime_content_type($fullPath) ?: 'image/jpeg';

                return response()->file($fullPath, [
                    'Content-Type'  => $mimeType,
                    'Cache-Control' => 'public, max-age=3600',
                ]);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        return response()->json(['error' => 'Image not found'], 404);
    }
}
