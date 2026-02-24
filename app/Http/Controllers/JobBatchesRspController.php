<?php

namespace App\Http\Controllers;

use App\Http\Requests\JobPostRepublishedRequest;
use App\Http\Requests\JobPostStoreRequest;
use App\Http\Requests\JobPostUpdateRequest;
use Carbon\Carbon;
use App\Models\Submission;
use App\Models\rating_score;
use Illuminate\Http\Request;
use App\Models\JobBatchesRsp;
use App\Models\OnCriteriaJob;
use App\Models\OnFundedPlantilla;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Models\vwplantillastructure;
use App\Services\ApplicantService;
use App\Services\JobPostService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;


class JobBatchesRspController extends Controller
{

    //  updating the status to Unoccupied
    public function jobpostUnoccupied(Request $request, $JobPostingId, JobPostService $jobPostService)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:Unoccupied',
        ]);

        $result = $jobPostService->unoccupied($validated,$JobPostingId);

        return $result;

    }

    //  this function fetching the only didnt meet the end_date
    public function availableJobPost(JobPostService $jobPostService)
    {
        // fetching available job post
        $result = $jobPostService->jobpostListAvailable();

        return $result;
    }

    // job post list
    public function jobPost(JobPostService $jobPostService)
    {

        $result = $jobPostService->jobPostList();

        return $result;

    }

    // filter the job post
    public function jobPostFiltered($postDate = null, $endDate = null, Request $request, JobPostService $jobPostService)
    {
        $result = $jobPostService->filter($postDate,$endDate,$request);

        return $result;
    }


    // fetch the job post
    // with or without criteria
    public function jobListCriteria(JobPostService $jobPostService)
    {

        $result = $jobPostService->fetchJobPostWithCriteria();

        return $result;
    }



    // public function show($positionId, $itemNo): JsonResponse
    // {
    //     $jobBatch = JobBatchesRsp::where('PositionID', $positionId)
    //         ->where('ItemNo', $itemNo)
    //         ->first();

    //     if (!$jobBatch) {
    //         return response()->json(['error' => 'No matching record found'], 404);
    //     }

    //     return response()->json($jobBatch);
    // }


    // Delete job post
    public function deleteJobPost($id, JobPostService $jobPostService)
    {
        $result = $jobPostService->delete($id);

        return $result;
    }

    // get the applicant base on the job post id
    public function getJobPostApplicant($id, JobPostService $jobPostService)
    {
         // args id = jobpostId

        $result = $jobPostService->applicant($id);

        return $result;

    }


    public function jobPostView($job_post_id)
    {
        // ✅ Fetch job post with relations
        $job_post = JobBatchesRsp::with(['criteria', 'plantilla'])
            ->withCount([
                'submissions as total_applicants',
                'submissions as qualified_count' => function ($query) {
                    $query->whereRaw('LOWER(status) = ?', ['qualified']);
                },
                'submissions as unqualified_count' => function ($query) {
                    $query->whereRaw('LOWER(status) = ?', ['unqualified']);
                },
                'submissions as pending_count' => function ($query) {
                    $query->whereRaw('LOWER(status) = ?', ['pending']);
                },
                'submissions as hired_count' => function ($query) {
                    $query->whereRaw('LOWER(status) = ?', ['hired']);
                },
            ])
            ->findOrFail($job_post_id);

        // ✅ Check if all raters completed their rating
        $allRatersComplete = \App\Models\Job_batches_user::where('job_batches_rsp_id', $job_post->id)
            ->exists() &&
            !\App\Models\Job_batches_user::where('job_batches_rsp_id', $job_post->id)
                ->where('status', '!=', 'complete')
                ->exists();

        $originalStatus = strtolower($job_post->status);
        $newStatus = $originalStatus;

        // ✅ Skip manual statuses
        $manualStatuses = ['unoccupied', 'occupied', 'closed', 'republished'];
        if (!in_array($originalStatus, $manualStatuses)) {
            if ($allRatersComplete) {
                $newStatus = 'rated';
            } elseif ($job_post->hired_count >= 1) {
                $newStatus = 'occupied';
            } elseif ($job_post->qualified_count > 0 || $job_post->unqualified_count > 0) {
                // ✅ If there’s at least one qualified or unqualified applicant
                $newStatus = $job_post->pending_count > 0 ? 'pending' : 'assessed';
            } else {
                $newStatus = 'not started';
            }

            // ✅ Update only if status changed
            if ($originalStatus !== $newStatus) {
                $job_post->status = $newStatus;
                $job_post->save();
            }
        }

        // ✅ Get complete history (both previous and next reposts)
        $history = $this->getFullJobHistory($job_post);

        // ✅ Convert to array and clean up nested relations
        $job_post_array = $job_post->toArray();
        unset($job_post_array['previous_job'], $job_post_array['next_job']);

        // ✅ Return structured response
        return response()->json(array_merge($job_post_array, [
            'history' => $history,
        ]));
    }


    private function getFullJobHistory($job)
    {
        $history = [];

        // 1️⃣ Go backwards (older reposts)
        $current = $job;
        while ($current->previousJob) {
            $current = $current->previousJob;
        }

        // 2️⃣ From the oldest, go forward (to latest reposts)
        while ($current) {
            $history[] = [
                'id' => $current->id,
                'post_date' => $current->post_date,
                'end_date' => $current->end_date,
            ];

            $current = $current->nextJob ?? null; // move forward
        }

        return $history; // always ordered oldest → latest
    }

    // store job post
    public function storeJobPost(JobPostStoreRequest $request, JobPostService $jobPostService)
    {
        // Validate basic fields for job batch
        $jobValidated = $request->validated();

        $result = $jobPostService->store($jobValidated,$request);

        return $result;

    }

    // updating job post
    public function updateJobPost(JobPostUpdateRequest $request, $jobBatchId, JobPostService $jobPostService)
    {
        // 1️⃣ Validate job batch fields
        $jobValidated = $request->validated();

      $result = $jobPostService->update($jobValidated,$jobBatchId,$request);

      return $result;


    }

    // republished the job post
    public function republishedJobPost(JobPostRepublishedRequest $request, JobPostService $jobPostService)
    {
        // validation
        $validated = $request->validated();

        $result = $jobPostService->republished($validated,$request);

        return $result;
    }


    // get the pdf of applicant
    public function applicantPds($id, ApplicantService $applicantService)
    {
        // args id = applicant_id

        $result = $applicantService->getApplicantPds($id);

        return $result;

    }

    // fetch the  jobpost with rated and occupied position
    public function jobPostCompleteStatus()
    {
        $jobs = JobBatchesRsp::select('id as jobpostId', 'Office', 'Position','status', 'post_date', 'end_date')
            ->whereIn('status', ['Republished', 'rated', 'Unoccupied'])
            ->get();

        return response()->json($jobs);
    }



    public function jobPostPostDate()    // fetch job post based on the post date
    {
        $dates = JobBatchesRsp::select('post_date')
            ->whereIn('status', ['Republished', 'rated', 'Unoccupied','assessed'])
            ->distinct()
            ->orderBy('post_date', 'desc')
            ->get();

        $formattedDate = $dates->map(function ($item) {
            return [
                // 'date'      => $item->post_date, // RAW date (for API logic)
                'date' => Carbon::parse($item->post_date)->format('M d, Y'), // UI only
            ];
        });

        return response()->json($formattedDate);
    }
}
