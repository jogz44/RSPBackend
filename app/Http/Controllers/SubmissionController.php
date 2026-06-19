<?php

namespace App\Http\Controllers;

use App\Http\Requests\EvaluationRequest;
use App\Models\Submission;
use Illuminate\Http\Request;
use App\Services\ActivityLogService;
use App\Services\SubmissionService;
use App\Traits\ApiResponseTrait;
use Illuminate\Support\Facades\Auth;


class SubmissionController extends Controller
{
    use ApiResponseTrait;

    // service for handling the status of the applicant
    protected SubmissionService $submissionService;
    protected ActivityLogService $activityLogService;

    public function __construct(SubmissionService $submissionService, ActivityLogService $activityLogService)
    {
        $this->submissionService = $submissionService;
        $this->activityLogService = $activityLogService;
    }

    // deleting applicant on the job_post he/she applicant
    public function delete(int $id)
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
    public function evaluation(EvaluationRequest $request, int $id)
    {
        $validated = $request->validated();

        $result = $this->submissionService->status($validated, $id, $request);

        return $result;
    }


    // getting the image of the internal applicant
    public function getImageInternalApplicant(int $submissionId)
    {
        $data = $this->submissionService->proxyImage($submissionId);
        return $data;
    }


    // delete applicant submission
    public function deleteApplicantSubmission(int $submissionId, Request $request)
    {
        $submission = Submission::where('id', $submissionId)->where('status', 'pending')->first(); // use find() instead of findOrFail()


        if (!$submission) {
            return response()->json([
                'success' => false,
                'message' => 'Submission id  not found or is not pending.',
            ], 404);
        }

        $submission->delete();

        $user = Auth::user();

        $this->activityLogService->logDeleteApplicantApplied($user, $submission);


        return response()->json([
            'success' => true,
            'message' => 'Submission deleted successfully.',
        ]);
    }

    // tag_color of applicant
    public function tagColor(Request $request)
    {
        $validated = $request->validate([
            'submission_id' => 'required|array|min:1',        // array|min:1 not array:min1
            'submission_id.*' => 'required|exists:submission,id', // exists not exist, wildcard for array, lowercase id
            'tag_color' => 'nullable',
        ]);

        // whereIn since submission_id is an array
        $updated = Submission::whereIn('id', $validated['submission_id'])
            ->update(['tag_color' => $validated['tag_color'] ?? null]);
            
        return $this->successMessage($updated, 'Tag color updated successfully', 200);
    }

    // application_status
    public function applicantStatusUpdate(Request $request)
    {
        $validated = $request->validate([
            'submission_id'      => 'required|exists:submission,id',
            'application_status' => 'required|string'
        ]);

        $updateApplication = Submission::where('id', $validated['submission_id'])
            ->update(['application_status' => $validated['application_status']]);

        return $this->successMessage($updateApplication, 'Applicant Status updated successfully', 200);
    }
}
