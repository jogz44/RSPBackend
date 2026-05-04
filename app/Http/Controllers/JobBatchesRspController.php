<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\JobPostRepublishedRequest;
use App\Http\Requests\JobPostStoreRequest;
use App\Http\Requests\JobPostUpdateRequest;

use App\Models\Job_batches_user;
use App\Models\JobBatchesRsp;

use App\Models\xService;
use App\Services\ApplicantService;
use App\Services\JobPostService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Calculation\Web\Service;



class JobBatchesRspController extends Controller
{

    //  updating the status to Unoccupied
    public function jobpostUnoccupied(Request $request, $JobPostingId, JobPostService $jobPostService)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:Unoccupied',
        ]);

        $result = $jobPostService->unoccupied($validated, $JobPostingId);

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
        $result = $jobPostService->filter($postDate, $endDate, $request);

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
    public function getJobPostApplicant($id, JobPostService $jobPostService, Request $request)
    {
        // args id = jobpostId

        $result = $jobPostService->applicant($id, $request);

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

        $result = $jobPostService->store($jobValidated, $request);

        return $result;
    }

    // updating job post
    public function updateJobPost(JobPostUpdateRequest $request, $jobBatchId, JobPostService $jobPostService)
    {
        // 1️⃣ Validate job batch fields
        $jobValidated = $request->validated();

        $result = $jobPostService->update($jobValidated, $jobBatchId, $request);

        return $result;
    }

    // republished the job post
    public function republishedJobPost(JobPostRepublishedRequest $request, JobPostService $jobPostService)
    {
        // validation
        $validated = $request->validated();

        $result = $jobPostService->republished($validated, $request);

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
        $jobs = JobBatchesRsp::select('id as jobpostId', 'Office', 'Position', 'status', 'post_date', 'end_date')
            ->whereIn('status', ['Republished', 'rated', 'Unoccupied', 'Occupied'])
            ->get();

        return response()->json($jobs);
    }

    // fetch the jobpost have bei and status rater or occupied
    public function jostPostWithBei()
    {
        $jobs = JobBatchesRsp::select('id', 'Office', 'Position', 'status', 'post_date', 'end_date')
            ->with(['criteriaRatings' => function ($query) {
                $query->with(['behaviorals']);
            }])
            ->whereIn('status', ['Republished', 'rated', 'Unoccupied', 'Occupied'])
            ->whereHas('criteriaRatings.behaviorals') // only jobs that have at least 1 behavioral
            ->get();

        return response()->json($jobs);
    }


    // // fetch the  jobpost with rated and occupied position
    // public function jobPostList()
    // {
    //     $jobs = JobBatchesRsp::select('id as jobpostId', 'Office', 'Position', 'status', 'post_date', 'end_date')
    //         // ->whereIn('status', ['Republished', 'rated', 'Unoccupied'])
    //         ->get();

    //     return response()->json($jobs);
    // }


    // fetch job post based on the post date all position
    public function jobPostPostDate()
    {
        $dates = JobBatchesRsp::select('post_date')
            ->whereIn('status', ['Republished', 'rated', 'Unoccupied', 'assessed'])
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


    // fetch job Occupied
    public function jobPostPublicationOccupied()
    {
        // Step 1: Get all distinct post_dates with status Occupied
        $dates = JobBatchesRsp::select('post_date')
            ->where('status', 'Occupied')
            ->distinct()
            ->orderBy('post_date', 'desc')
            ->get();

        // Step 2: For each date, get job posts and their effective dates
        $result = $dates->map(function ($item) {
            $postDate = $item->post_date;

            $jobPosts = JobBatchesRsp::with(['submissions' => function ($query) {
                $query->where('status', 'Hired')
                    ->with('nPersonalInfo');
            }])
                ->whereDate('post_date', $postDate)
                ->where('status', 'Occupied')
                ->get();

            // Flatten all hired applicants across all job posts for this date
            $effectiveDates = $jobPosts->flatMap(function ($jobPost) {
                return $jobPost->submissions->map(function ($submission) use ($jobPost) {
                    $info = $submission->nPersonalInfo;

                    $xPersonal = null;
                    if (!$info && $submission->ControlNo) {
                        $xPersonal = DB::table('xPersonal')
                            ->where('ControlNo', $submission->ControlNo)
                            ->select('Firstname', 'Middlename', 'Surname')
                            ->first();
                    }

                    $name = null;
                    if ($info) {
                        $name = trim("{$info->firstname} {$info->middlename} {$info->lastname}");
                    } elseif ($xPersonal) {
                        $name = trim("{$xPersonal->Firstname} {$xPersonal->Middlename} {$xPersonal->Surname}");
                    }

                    $service = xService::where('submission_id', $submission->id)->first();

                    return [
                        // 'submission_id' => $submission->id,
                        // 'control_no'    => $submission->ControlNo,
                        // 'name'          => $name,
                        // 'salary_grade'  => $jobPost->SalaryGrade,
                        // 'ItemNo'        => $jobPost->ItemNo,
                        // 'designation'   => $jobPost->Position,
                        'effectiveDate' => $service?->effectiveDate
                            ? Carbon::parse($service->effectiveDate)->format('M d, Y')
                            : null,
                    ];
                });
            });

            return [
                'date'                   => Carbon::parse($postDate)->format('M d, Y'),
                'effective_date_available' => $effectiveDates->values(), // ← flattened list
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $result,
        ]);
    }

    //  // proxy
    // public function proxyPdsImage($filename, ApplicantService $applicantService)
    // {
    //    $data =  $this->$applicantService->proxyPdsImage($filename);


    //    return $data;
    // }


    // public function getInternalPdsImage($controlNo)
    // {
    //     $training    = $this->getTrainingImage($controlNo);
    //     $education   = $this->getEducationImage($controlNo);
    //     $experience  = $this->getExperienceImage($controlNo);
    //     $eligibility = $this->getEligibilityImage($controlNo);

    //     $baseUrl = config('app.network_share_img_pds.base_url'); // e.g. http://10.0.1.26:82/Pics

    //     $buildUrls = function ($records) use ($baseUrl) {
    //         return collect($records)->map(function ($record) use ($baseUrl) {
    //             $data = (array) $record;
    //             if (!empty($record->img)) {
    //                 $data['image_url']        = "{$baseUrl}/{$record->img}";
    //                 $data['image_url_access'] = config('app.url') . '/api/pds-image/' . $record->img; // http://192.168.8.182:8000/api/pds-image/97cba103f6478a078a7b.png
    //             } else {
    //                 $data['image_url']        = null;
    //                 $data['image_url_access'] = null;
    //             }
    //             return $data;
    //         })->values();
    //     };

    //     return response()->json([
    //         'control_no'          => $controlNo,
    //         'training_images'     => $buildUrls($training),
    //         'education_images'    => $buildUrls($education),
    //         'experience_images'   => $buildUrls($experience),
    //         'eligibility_images'  => $buildUrls($eligibility),
    //     ]);
    // }



    // // training images — all records
    // private function getTrainingImage($controlNo)
    // {
    //     return DB::table('tblPDSUpdatesTrainings')
    //         ->select('ID', 'Controlno', 'Training', 'img', 'status')
    //         ->where('Controlno', $controlNo)
    //         ->where('status', 'ACCEPTED')
    //         ->get();
    // }

    // // education images — all records
    // private function getEducationImage($controlNo)
    // {
    //     return DB::table('tblPDSUpdatesEducation')
    //         ->select('ID', 'ControlNo', 'School', 'img', 'status')
    //         ->where('ControlNo', $controlNo)
    //         ->where('status', 'ACCEPTED')
    //         ->get();
    // }

    // // experience images — all records
    // private function getExperienceImage($controlNo)
    // {
    //     return DB::table('tblPDSUpdatesWorkExperience')
    //         ->select('ID', 'controlno', 'Wposition', 'img', 'status')
    //         ->where('ControlNo', $controlNo)
    //         ->where('status', 'ACCEPTED')
    //         ->get();
    // }

    // // eligibility images — all records
    // private function getEligibilityImage($controlNo)
    // {
    //     return DB::table('tblPDSUpdatesCivilService')
    //         ->select('ID', 'controlno', 'CivilServe', 'img', 'status')
    //         ->where('ControlNo', $controlNo)
    //         ->where('status', 'ACCEPTED')
    //         ->get();
    // }


    // training images — all records
    public function getInternalPdsImage($controlNo, ApplicantService $applicantService)
    {
        return $applicantService->getInternalPdsImage($controlNo);
    }


    // PROXY the image using my app url
    public function proxyPdsImage($filename)
    {
        $baseUrl = config('app.network_share_img_pds.base_url');
        $imageUrl = "{$baseUrl}/{$filename}";

        $response = Http::get($imageUrl);

        if (!$response->successful()) {
            return response()->json(['message' => 'Image not found'], 404);
        }

        return response($response->body(), 200)
            ->header('Content-Type', $response->header('Content-Type'));
    }
}
