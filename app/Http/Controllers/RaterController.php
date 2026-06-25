<?php

namespace App\Http\Controllers;


use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicantDetailsResource;
use App\Http\Resources\ApplicantRaterResource;
use App\Models\criteria\criteria_rating;
use App\Models\Job_batches_user;
use App\Models\JobBatchesRsp;
use App\Models\rating_score;
use App\Models\Submission;
use App\Models\User;
use App\Services\ApplicantService;
use App\Services\RaterService;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class RaterController extends Controller
{
    use ApiResponseTrait;

    protected $raterService;
    protected $applicantService;

    public function __construct(RaterService $raterService, ApplicantService $applicantService)
    {
        $this->raterService = $raterService;
        $this->applicantService = $applicantService;
    }

    //  view rater details assigned jobs
    public function viewRater($raterId)
    {
        $result = $this->raterService->viewDetails($raterId);

        return $result;
    }

    //  fetch the list of job post only didnt assign and assigned on rater
    public function jobPost($raterId)
    {
        $result = $this->raterService->jobPostList($raterId);

        return $result;
    }

    // fetch applicant with score
    public function fetchApplicant($jobpostId, Request $request) // fetch the score of the applicant
    {

        $result = $this->applicantService->score($jobpostId, $request);

        return $result;
    }




    // applicant score individual
    public function applicantScoreIndividual($applicantId, $jobpostId) // applicant rating score
    {
        $result = $this->applicantService->applicantScoreDetials($applicantId, $jobpostId);

        return $result;
    }

    public function index()
    {

        $data = rating_score::all();

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    // fetch assigned job post on rater
    public function listOfAssignedJobPost(Request $request)
    {
        $result = $this->raterService->getAssignedJobs($request);
        return $result;
    }

    // // fetch assigned job post on rater
    public function listOfApplicantRaterAssigned(Request $request)
    {
        $result = $this->raterService->getApplicantBaseOnRaterAssigned($request);

        return $result;
    }

    // fetch applicant details of the assigned job post on rater
    public function applicantAppliedJobDetails(Request $request)
    {
        $result = $this->raterService->getApplicantDetails($request);

        if ($result->isEmpty()) {
            return response()->json([
                'status'  => false,
                'message' => 'No applicant found with the provided details.',
            ], 404);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Applicants retrieved successfully.',

            'data'    => ApplicantDetailsResource::collection($result),
        ]);
    }

    // fetch the criteria and applicant of job post
    public function fetchCriteriaAndApplicant($id)
    {
        // args id = jobpostId

        $result = $this->raterService->getCriteriaOfJobpostAndApplicant($id);

        return  $result;
    }

    // fetch raters
    public function fetchRater()
    {
        $result = $this->raterService->getAllRaters();

        return $result;
    }

    // this function will fetch all rater username on the login page
    public function fetchRaterAccountLogin()
    {
        $result = $this->raterService->get_rater_usernames();

        return $result;
    }

    // store applicant scores
    public function storeApplicantScore(Request $request) // storing the score of the applicant
    {
        $result = $this->raterService->storeScore($request);

        return $result;
    }

    // draft the score of applicants
    public function draftApplicantScore(Request $request)
    {
        $result = $this->raterService->draftScore($request);

        return  $result;
    }


    // deleting applicant on the job_post he/she applicant
    public function delete($id)
    {
        $submission = rating_score::find($id);

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

    // // fetch the job post only assess,pending, not started
    // public function jobListAssigned()
    // {
    //     // ✅ Fetch only job posts excluding 'unoccupied' and 'occupied'
    //     $jobs = JobBatchesRsp::select('id', 'Office', 'Position', 'status')
    //         ->whereNotIn('status', ['unoccupied', 'occupied', 'republished'])
    //         ->get();


    //     $office = DB::table('yOffice')->select('Descriptions', 'Abbr')->where('Descriptions', $jobs->Office)->first();

    //     return response()->json($jobs);
    // }

    public function jobListAssigned()
    {
        // ✅ Fetch job posts excluding certain statuses
        $jobs = JobBatchesRsp::select('id', 'Office', 'Position', 'status')
            ->whereNotIn('status', ['unoccupied', 'occupied', 'republished'])
            ->get();

        // ✅ Get unique office names from the job list
        $officeNames = $jobs->pluck('Office')->unique()->values();

        // ✅ Fetch abbreviations for those offices only
        $officeAbbrs = DB::table('yOffice')
            ->select('Descriptions', 'Abbr')
            ->whereIn('Descriptions', $officeNames)
            ->get()
            ->keyBy('Descriptions'); // key by office name for easy lookup

        // ✅ Map abbr into each job
        $jobs = $jobs->map(function ($job) use ($officeAbbrs) {
            $job->office_abbr = $officeAbbrs->get($job->Office)?->Abbr ?? null;
            return $job;
        });

        return response()->json($jobs);
    }


    // fetch the rater with the assigned job post
    public function raterWithJob($jobPostId)
    {

        $data = $this->raterService->raterWithAssignedJob($jobPostId);

        return $data;
    }


    // get the score of the applicant on the rating_score
    public function getApplicantScore(Request $request) // jobpost id
    {

        $validated = $request->validate([
            'userId'    => 'required|integer|exists:users,id',
            'jobPostId' => 'required|integer|exists:job_batches_rsp,id',
        ]);

        $data = $this->raterService->getScoreOfApplicantRateByRater($validated);

        return $data;
    }

    //updating job assigned rating status
    public function updateJobAssignedRatingStatus(Request $request)
    {
        $validatedData = $request->validate([
            'job_post_assign_id' => 'required|exists:job_batches_user,id', // fixed: exist -> exists
            'status'             => 'required|in:Returned',        // add valid statuses
        ]);

        $data = Job_batches_user::find($validatedData['job_post_assign_id']);

        if (!$data) {
            return $this->errorMessage('Rater not found.', 404);
        }

        $data->update([
            'status' => $validatedData['status'],
        ]);

        return $this->successMessage($data, 'Status updated successfully.', 200);
    }
}
