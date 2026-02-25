<?php

namespace App\Http\Controllers;


use App\Http\Controllers\Controller;
use App\Models\criteria\criteria_rating;
use App\Models\draft_score;
use App\Models\Job_batches_user;
use App\Models\JobBatchesRsp;
use App\Models\rating_score;
use App\Models\Submission;
use App\Models\User;
use App\Services\ApplicantService;
use App\Services\RaterService;
use App\Services\RatingService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class RaterController extends Controller
{

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

    // fetch applicant with score
    public function fetchApplicant($jobpostId,) // fetch the score of the applicant
    {

        $result = $this->applicantService->score($jobpostId);

        return $result;

    }

    // applicant score individual
    public function applicantScoreIndividual($applicantId) // applicant rating score
    {
        $result = $this->applicantService->applicantScoreDetials($applicantId);

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


    public function jobListAssigned()
    {
        // ✅ Fetch only job posts excluding 'unoccupied' and 'occupied'
        $jobs = JobBatchesRsp::select('id', 'Office', 'Position', 'status')
            ->whereNotIn('status', ['unoccupied', 'occupied','republished'])
            ->get();

        return response()->json($jobs);
    }

    public function applicantHistoryScore(){ // history score of the applicant


    }



}
