<?php

namespace App\Services;

use App\Models\criteria\criteria_rating;
use App\Models\Job_batches_user;
use App\Models\JobBatchesRsp;
use App\Models\rating_score;
use App\Models\Submission;
use App\Models\vwActive;
use App\Models\xPersonal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ApplicantService
{
    /**
     * Create a new class instance.
     */
    // public function __construct()
    // {
    //     //
    // }

    /**
     * Fetch all applicants (internal + external) from the full job post history
     */
    public function applicant($job_post_id)
    {
        // Step 1: Fetch the base job post
        $job_post = JobBatchesRsp::with(['previousJob', 'nextJob'])->findOrFail($job_post_id);

        //  Step 2: Get full history (oldest → latest)
        $history = $this->getFullJobHistory($job_post);
        $job_ids = collect($history)->pluck('id');

        // Step 3: Fetch submissions for all job IDs in history
        $submissions = Submission::select('id', 'nPersonalInfo_id', 'ControlNo', 'job_batches_rsp_id', 'status')
            ->whereIn('job_batches_rsp_id', $job_ids)
            ->with([
                'nPersonalInfo:id,firstname,lastname', // internal applicants
                'xPersonal:ControlNo,Firstname,Surname', // external applicants
            ])
            ->get();

        // Step 4: Get applicants already stored for current job
        $existing = Submission::where('job_batches_rsp_id', $job_post_id)
            ->get(['nPersonalInfo_id', 'ControlNo']);

        $existingInternal = $existing->pluck('nPersonalInfo_id')->filter()->toArray();
        $existingExternal = $existing->pluck('ControlNo')->filter()->toArray();

        // Step 5: Combine internal and external applicants, excluding existing
        $applicants = $submissions->map(function ($item) use ($existingInternal, $existingExternal) {
            if ($item->nPersonalInfo_id && $item->nPersonalInfo && !in_array($item->nPersonalInfo_id, $existingInternal)) {
                // Internal applicant
                return [
                    'nPersonalInfo_id' => $item->nPersonalInfo_id,
                    'type' => 'internal',
                    'firstname' => $item->nPersonalInfo->firstname,
                    'lastname' => $item->nPersonalInfo->lastname,
                    'status' => $item->status,
                ];
            } elseif ($item->ControlNo && $item->xPersonal && !in_array($item->ControlNo, $existingExternal)) {
                // External applicant
                return [
                    'ControlNo' => $item->ControlNo,
                    'type' => 'external',
                    'firstname' => $item->xPersonal->Firstname,
                    'lastname' => $item->xPersonal->Surname,
                    'status' => $item->status,
                ];
            }
            return null;
        })->filter()->values();

        return response()->json($applicants);
    }

    /**
     * Helper: get full repost chain (oldest → latest)
     */
    private function getFullJobHistory($job)
    {
        $history = [];

        // Move to oldest
        $current = $job;
        while ($current->previousJob) {
            $current = $current->previousJob;
        }

        // Collect all including latest
        while ($current) {
            $history[] = $current;
            $current = $current->nextJob ?? null;
        }

        return $history;
    }

    // export applicant on the job post
    //
    public function store($validated, $request)
    {

        $user = Auth::user(); // Get the authenticated user
        /**
         * job_batches_rsp_id => Job Post ID
         * applicants => array that can contain either:
         *    { "id": <nPersonalInfo_id> } OR { "ControlNo": <ControlNo> }
         */

        $jobPostId = $validated['job_batches_rsp_id'];

        // Get job post details for logging
        $jobPost = \App\Models\JobBatchesRsp::find($jobPostId);
        $position = $jobPost->Position ?? 'N/A';
        $office = $jobPost->Office ?? 'N/A';

        // ✅ Build insert data
        $insertData = collect($validated['applicants'])->map(function ($applicant) use ($jobPostId) {
            return [
                'job_batches_rsp_id' => $jobPostId,
                'nPersonalInfo_id' => $applicant['id'] ?? null,
                'ControlNo' => $applicant['ControlNo'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })
            // Filter out invalid entries (both id and ControlNo are null)
            ->filter(fn($item) => $item['nPersonalInfo_id'] || $item['ControlNo'])
            ->toArray();

        // ✅ Insert all applicants
        DB::table('submission')->insert($insertData);

        activity('Applicants')
            ->causedBy($user)
            ->performedOn($jobPost)
            ->withProperties([
                'name' => $user->name,
                'position' => $position,
                'office' => $office,
                'applicants_added_count' => count($insertData),
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ])
            ->log("User '{$user->name}' added " . count($insertData) . " applicant(s) to the job post '{$position}' in '{$office}'.");
        return response()->json([
            'message' => 'Applicants stored successfully!',
            'count' => count($insertData),
        ], 201);
    }


    // get the pds of applicant base on the submission id
    public function getApplicantPds($id)
    {
        // 🔹 Fetch the submission by its ID
        $submission = Submission::find($id);

        if (!$submission) {
            return response()->json([
                'status' => false,
                'message' => 'Applicant not found.'
            ], 404);
        }

        $info = $submission->nPersonalInfo;

        // If no local personal info but has ControlNo — fetch from employee DB
        if (!$info && $submission->ControlNo) {
            $xPDS = new \App\Http\Controllers\xPDSController();
            $employeeData = $xPDS->getPersonalDataSheet(
                new \Illuminate\Http\Request(['controlno' => $submission->ControlNo])
            );
            $employeeJson = $employeeData->getData(true);

            $info = [
                'controlno' => $submission->ControlNo,
                'firstname' => $employeeJson['User'][0]['Firstname'] ?? '',
                'lastname' => $employeeJson['User'][0]['Surname'] ?? '',
                'middlename' => $employeeJson['User'][0]['MIddlename'] ?? '',
                'name_extension' => $employeeJson['User'][0]['NameExtension'] ?? null,
                'image_path' => $employeeJson['User'][0]['Pics'] ?? null,
                'date_of_birth' => $employeeJson['User'][0]['BirthDate'] ?? 'N/A',
                'place_of_birth' => $employeeJson['User'][0]['BirthPlace'] ?? 'N/A',
                'sex' => $employeeJson['User'][0]['Sex'] ?? 'N/A',
                'civil_status' => $employeeJson['User'][0]['CivilStatus'] ?? 'N/A',
                'height' => $employeeJson['User'][0]['Heights'] ?? 'N/A',
                'weight' => $employeeJson['User'][0]['Weights'] ?? 'N/A',
                'blood_type' => $employeeJson['User'][0]['BloodType'] ?? 'N/A',
                'telephone_number' => $employeeJson['User'][0]['TelNo'] ?? 'N/A',
                'email_address' => $employeeJson['User'][0]['EmailAdd'] ?? 'N/A',
                'cellphone_number' => $employeeJson['User'][0]['CellphoneNo'] ?? 'N/A',
                'tin_no' => $employeeJson['User'][0]['TINNo'] ?? 'N/A',
                'gsis_no' => $employeeJson['User'][0]['GSISNo'] ?? 'N/A',
                'pagibig_no' => $employeeJson['User'][0]['PAGIBIGNo'] ?? 'N/A',
                'sss_no' => $employeeJson['User'][0]['SSSNo'] ?? 'N/A',
                'philhealth_no' => $employeeJson['User'][0]['PHEALTHNo'] ?? 'N/A',
                'agency_employee_no' => $employeeJson['User'][0]['ControlNo'] ?? 'N/A',
                'citizenship' => $employeeJson['User'][0]['Citizenship'] ?? 'N/A',
                'religion' => $employeeJson['User'][0]['Religion'] ?? 'N/A',



                // Residential address
                'residential_house' => $employeeJson['User'][0]['Rhouse'] ?? null,
                'residential_street' => $employeeJson['User'][0]['Rstreet'] ?? null,
                'residential_subdivision' => $employeeJson['User'][0]['Rsubdivision'] ?? null,
                'residential_barangay' => $employeeJson['User'][0]['Rbarangay'] ?? null,
                'residential_city' => $employeeJson['User'][0]['Rcity'] ?? null,
                'residential_province' => $employeeJson['User'][0]['Rprovince'] ?? null,
                'residential_region' => $employeeJson['User'][0]['Rregion'] ?? null,
                'residential_zip' => $employeeJson['User'][0]['Rzip'] ?? null,

                // Permanent address
                'permanent_region' => $employeeJson['User'][0]['Pregion'] ?? null,
                'permanent_house' => $employeeJson['User'][0]['Phouse'] ?? null,
                'permanent_street' => $employeeJson['User'][0]['Pstreet'] ?? null,
                'permanent_subdivision' => $employeeJson['User'][0]['Psubdivision'] ?? null,
                'permanent_barangay' => $employeeJson['User'][0]['Pbarangay'] ?? null,
                'permanent_city' => $employeeJson['User'][0]['Pcity'] ?? null,
                'permanent_province' => $employeeJson['User'][0]['Pprovince'] ?? null,
                'permanent_zip' => $employeeJson['User'][0]['Pzip'] ?? null,

                'education' => $employeeJson['Education'] ?? [],
                'eligibity' => $employeeJson['Eligibility'] ?? [],
                'training' => $employeeJson['Training'] ?? [],
                'work_experience' => $employeeJson['Experience'] ?? [],
                'voluntary_work' => $employeeJson['Voluntary'] ?? [],
                'skills' => $employeeJson['Skills'] ?? [],
                'Academic' => $employeeJson['Academic'] ?? [],
                'Organization' => $employeeJson['Organization'] ?? [],
                'reference' => $employeeJson['Reference'] ?? [],

                'children' => collect($employeeJson['User'][0]['children'] ?? [])->map(function ($child) {
                    return [
                        'child_name' => $child['ChildName'] ?? $child['child_name'] ?? null,
                        'birth_date' => $child['BirthDate'] ?? $child['birth_date'] ?? null,
                    ];
                })->toArray(),
                'family' => [], // optional
            ];
        }

        // Fetch ranking from rating_score
        $rating = rating_score::where('nPersonalInfo_id', $submission->nPersonalInfo_id)
            ->where('job_batches_rsp_id', $id)
            ->first();



        // Generate image URL
        $imageUrl = null;
        $rawImagePath = $info['image_path'] ?? null;

        if ($rawImagePath) {
            // Case 1: Already a valid HTTP URL (external applicant from MinIO/storage)
            if (filter_var($rawImagePath, FILTER_VALIDATE_URL)) {
                $imageUrl = $rawImagePath;
            }
            // Case 2: Local storage path
            elseif (Storage::disk('public')->exists($rawImagePath)) {
                $imageUrl = config('app.url') . '/storage/' . $rawImagePath;
            }
            // Case 3: Windows UNC path (\\server\...) — proxy it through your API
            elseif (str_starts_with($rawImagePath, '\\\\') || str_starts_with($rawImagePath, '//')) {
                $imageUrl = config('app.url') . '/api/proxy-image/' . $submission->id;
            }
        }


        $trainingImages    = [];
        $educationImages   = [];
        $eligibilityImages = [];
        $experienceImages  = [];

        // ✅ Use ControlNo folder for internal employees, nPersonalInfo_id for external
        // ✅ Use ControlNo folder for internal employees, nPersonalInfo_id for external
        $folderKey = $submission->ControlNo ?? ($info['id'] ?? $submission->nPersonalInfo_id ?? null);

        if ($folderKey) {
            // ✅ Include job_{id} in path for external applicants (ControlNo-based)
            $jobFolder = $submission->ControlNo
                ? "job_{$submission->job_batches_rsp_id}"
                : null;

            $baseFolder = storage_path('app/public/applicant_files/' . $folderKey . ($jobFolder ? '/' . $jobFolder : ''));

            $folders = [
                'training'    => $baseFolder . '/training',
                'education'   => $baseFolder . '/education',
                'eligibility' => $baseFolder . '/eligibility',
                'experience'  => $baseFolder . '/experience',
            ];

            foreach ($folders as $type => $path) {
                if (is_dir($path)) {
                    $files = collect(scandir($path))
                        ->filter(fn($file) => !in_array($file, ['.', '..']))
                        ->map(fn($file) => asset(
                            'storage/applicant_files/' . $folderKey .
                                ($jobFolder ? '/' . $jobFolder : '') .
                                '/' . $type . '/' . $file
                        ))
                        ->values()
                        ->toArray();

                    if ($type === 'training')    $trainingImages    = $files;
                    if ($type === 'education')   $educationImages   = $files;
                    if ($type === 'eligibility') $eligibilityImages = $files;
                    if ($type === 'experience')  $experienceImages  = $files;
                }
            }
        }


        return response()->json([
            // 'applicant_id' => $submission->id,
            'applicant_id' => $submission->id,

            'status' => $submission->status,
            'education_remark' => $submission->education_remark,
            'experience_remark' => $submission->experience_remark,
            'training_remark' => $submission->training_remark,
            'eligibility_remark' => $submission->eligibility_remark,
            'education_qualification' => $submission->education_qualification,
            'experience_qualification' => $submission->experience_qualification,
            'training_qualification' => $submission->training_qualification,
            'eligibility_qualification' => $submission->eligibility_qualification,

            'ControlNo' => $submission->ControlNo,
            'nPersonalInfo_id' => $submission->nPersonalInfo_id,
            'firstname' => $info['firstname'] ?? '',
            'lastname' => $info['lastname'] ?? '',
            'name_extension' => $info['name_extension'] ?? null,
            'image_path' => $info['image_path'] ?? null,
            'image_url' => $imageUrl,

            'date_of_birth' => $info['date_of_birth'] ?? null,
            'place_of_birth' => $info['place_of_birth'] ?? null,
            'sex' => $info['sex'] ?? null,
            'civil_status' => $info['civil_status'] ?? null,
            'height' => $info['height'] ?? null,
            'weight' => $info['weight'] ?? null,
            'blood_type' => $info['blood_type'] ?? null,
            'telephone_number' => $info['telephone_number'] ?? null,
            'email_address' => $info['email_address'] ?? null,
            'cellphone_number' => $info['cellphone_number'] ?? null,
            'tin_no' => $info['tin_no'] ?? null,
            'gsis_no' => $info['gsis_no'] ?? null,
            'pagibig_no' => $info['pagibig_no'] ?? null,
            'sss_no' => $info['sss_no'] ?? null,
            'philhealth_no' => $info['philhealth_no'] ?? null,
            'agency_employee_no' => $info['agency_employee_no'] ?? null,
            'citizenship' => $info['citizenship'] ?? null,
            'religion' => $info['religion'] ?? null,


            // Permanent Address
            'permanent_house' => $info['permanent_house'] ?? null,
            'permanent_street' => $info['permanent_street'] ?? null,
            'permanent_subdivision' => $info['permanent_subdivision'] ?? null,
            'permanent_barangay' => $info['permanent_barangay'] ?? null,
            'permanent_city' => $info['permanent_city'] ?? null,
            'permanent_province' => $info['permanent_province'] ?? null,
            'permanent_region' => $info['permanent_region'] ?? null,
            'permanent_zip' => $info['permanent_zip'] ?? null,

            // Residential Address
            'residential_house' => $info['residential_house'] ?? null,
            'residential_street' => $info['residential_street'] ?? null,
            'residential_subdivision' => $info['residential_subdivision'] ?? null,
            'residential_barangay' => $info['residential_barangay'] ?? null,
            'residential_city' => $info['residential_city'] ?? null,
            'residential_province' => $info['residential_province'] ?? null,
            'residential_region' => $info['residential_region'] ?? null,
            'residential_zip' => $info['residential_zip'] ?? null,

            'education' => $info['education'] ?? [],
            'training' => $info['training'] ?? [],
            'eligibity' => $info['eligibity'] ?? [],
            'work_experience' => $info['work_experience'] ?? [],
            'voluntary_work' => $info['voluntary_work'] ?? [],
            'skills' => $info['skills'] ?? [],
            'Academic' => $info['Academic'] ?? [],
            'Organization' => $info['Organization'] ?? [],
            'children' => $info['children'] ?? [],
            'family' => $info['family'] ?? [],
            'reference' => $info['reference'] ?? [],
            'personal_declarations' => $info['personal_declarations'] ?? [],
            'training_images' => $trainingImages,
            'education_images' => $educationImages,
            'eligibility_images' => $eligibilityImages,
            'experience_images' => $experienceImages,
            'ranking' => $rating->ranking ?? null,
        ], 200, [], JSON_UNESCAPED_SLASHES); // 👈 this is the key fix
    }

    public function score($jobpostId, $request)
    {
        $search = $request->input('search');
        $perPageInput = $request->input('per_page', 10);
        $currentPage = $request->input('page', 1);

        $perPage = ($perPageInput === 'all') ? PHP_INT_MAX : (int) $perPageInput;

        // $jobpost = JobBatchesRsp::findOrFail($jobpostId);
        $criteria = criteria_rating::with(['educations', 'trainings', 'experiences','performances','exams'])
        ->where('job_batches_rsp_id', $jobpostId)->get();

        $totalAssigned = Job_batches_user::where('job_batches_rsp_id', $jobpostId)
            ->whereHas('user', fn($q) => $q->where('active', 1))
            ->count();

        $totalCompleted = Job_batches_user::where('job_batches_rsp_id', $jobpostId)
            ->where('status', 'complete')
            ->count();

        $allScores = rating_score::select(
            'rating_score.id',
            'rating_score.user_id as rater_id',
            'users.name as rater_name',
            'rating_score.nPersonalInfo_id',
            'rating_score.ControlNo',
            'rating_score.job_batches_rsp_id',
            'rating_score.education_score as education',
            'rating_score.experience_score as experience',
            'rating_score.training_score as training',
            'rating_score.performance_score as performance',
            'rating_score.behavioral_score as bei',
            'rating_score.exam_score',
            'rating_score.total_qs',
            'rating_score.grand_total',
            'rating_score.ranking',
            'nPersonalInfo.firstname',
            'nPersonalInfo.lastname',
            'nPersonalInfo.image_path',
            'submission.id as submission_id'
        )
            ->leftJoin('nPersonalInfo', 'nPersonalInfo.id', '=', 'rating_score.nPersonalInfo_id')
            ->leftJoin('users', 'users.id', '=', 'rating_score.user_id')
            ->leftJoin('submission', function ($join) {
                $join->on('submission.job_batches_rsp_id', '=', 'rating_score.job_batches_rsp_id')
                    ->whereColumn('submission.nPersonalInfo_id', 'rating_score.nPersonalInfo_id');
            })
            ->where('rating_score.job_batches_rsp_id', $jobpostId)
            ->get();

        // Group by applicant
        $scoresByApplicant = $allScores->groupBy(fn($row) => $row->nPersonalInfo_id ?: 'control_' . $row->ControlNo);

        $applicants = [];

        foreach ($scoresByApplicant as $applicantKey => $scoreRows) {
            $firstRow = $scoreRows->first();

            $firstname = $firstRow->firstname;
            $lastname = $firstRow->lastname;

            // fallback
            if ((!$firstname || !$lastname) && $firstRow->ControlNo) {
                // $xPDS = new \App\Http\Controllers\xPDSController();
                // $employeeData = $xPDS->getPersonalDataSheet(new \Illuminate\Http\Request([
                //     'controlno' => $firstRow->ControlNo
                // ]));


                $active = xPersonal::where('ControlNo', $firstRow->ControlNo)->first();

                // if (!$active) {
                //     Log::warning('No vwActive match', [
                //         'ControlNo' => $firstRow->ControlNo
                //     ]);
                // } else {
                //     Log::info('vwActive found', $active->toArray());
                // }

                $firstname = $active->Firstname ?? $active->firstname ?? '';
                $lastname  = $active->Surname ?? $active->surname ?? '';
            }

            $scoresArray = $scoreRows->map(fn($row) => [
                'education'   => (float)$row->education,
                'experience'  => (float)$row->experience,
                'training'    => (float)$row->training,
                'performance' => (float)$row->performance,
                'bei'         => $row->bei,
                'exam_score'        => (float)$row->exam_score,
            ])->toArray();

            $computed = RatingService::computeFinalScore($scoresArray);

            $applicants[] = [
                'applicant_id'     => $firstRow->id,
                'nPersonalInfo_id' => (string)$firstRow->nPersonalInfo_id,
                'ControlNo'        => $firstRow->ControlNo,
                'jobpostId'        => (int)$firstRow->job_batches_rsp_id,
                'firstname'        => $firstname,
                'lastname'         => $lastname,
            ] + $computed;
        }

        // Convert to collection
        $collection = collect($applicants);

        // ✅ SEARCH FILTER
        if (!empty($search)) {
            $collection = $collection->filter(function ($item) use ($search) {
                return str_contains(strtolower($item['firstname']), strtolower($search)) ||
                    str_contains(strtolower($item['lastname']), strtolower($search)) ||
                    str_contains(strtolower($item['ControlNo']), strtolower($search));
            })->values();
        }

        // ✅ RANK AFTER FILTER
        $rankedApplicants = RatingService::addRanking($collection->values()->all());

        $collection = collect($rankedApplicants);

        // ✅ PAGINATION (MANUAL)
        $total = $collection->count();

        $paginatedData = $collection->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return response()->json([
            'jobpost_id'      => $jobpostId,
            'total_assigned'  => $totalAssigned,
            'total_completed' => $totalCompleted,
            'criteria' => $criteria,

            'data' => $paginatedData,

            'meta' => [
                'current_page' => (int)$currentPage,
                'per_page'     => (int)$perPage,
                'total'        => $total,
                'last_page'    => ceil($total / $perPage),
            ],
        ]);
    }



    // fetching the score details of the applicant base on the job batch id and applicant id
    // public function applicantScoreDetials($applicantId, $jobBatchId)
    // {

    //     $criteria = criteria_rating::with(['educations', 'trainings', 'experiences','performances','exams'])
    //     ->where('job_batches_rsp_id', $jobBatchId)->get();
    //     $historyRecords = rating_score::select(
    //         'rating_score.id',
    //         'rating_score.user_id as rater_id',
    //         'rater.name as rater_name',
    //         'rating_score.nPersonalInfo_id',
    //         'rating_score.ControlNo',
    //         'rating_score.job_batches_rsp_id',
    //         'rating_score.education_score as education',
    //         'rating_score.experience_score as experience',
    //         'rating_score.training_score as training',
    //         'rating_score.performance_score as performance',
    //         'rating_score.behavioral_score as bei',
    //         'rating_score.exam_score as exam',
    //         'rating_score.total_qs',
    //         'rating_score.grand_total',
    //         'rating_score.ranking',

    //         'nPersonalInfo.firstname as internal_firstname',
    //         'nPersonalInfo.lastname as internal_lastname',
    //         'nPersonalInfo.image_path as internal_image',

    //         'xPersonal.Firstname as external_firstname',
    //         'xPersonal.Surname as external_lastname',

    //         'submission.id as submission_id'
    //     )
    //         ->leftJoin('users as rater', 'rater.id', '=', 'rating_score.user_id')
    //         ->leftJoin('nPersonalInfo', 'nPersonalInfo.id', '=', 'rating_score.nPersonalInfo_id')
    //         ->leftJoin('xPersonal', 'xPersonal.ControlNo', '=', 'rating_score.ControlNo')
    //         ->leftJoin('xPersonalAddt', 'xPersonalAddt.ControlNo', '=', 'rating_score.ControlNo')
    //         ->leftJoin('submission', function ($join) {
    //             $join->on('submission.job_batches_rsp_id', '=', 'rating_score.job_batches_rsp_id')
    //                 ->where(function ($q) {
    //                     $q->on('submission.nPersonalInfo_id', '=', 'rating_score.nPersonalInfo_id')
    //                         ->orOn('submission.ControlNo', '=', 'rating_score.ControlNo');
    //                 });
    //         })
    //         ->where(function ($q) use ($applicantId) {
    //             $q->where('rating_score.nPersonalInfo_id', $applicantId)
    //                 ->orWhere('rating_score.ControlNo', $applicantId);
    //         })
    //         // ✅ Add this — filter by specific job batch
    //         ->where('rating_score.job_batches_rsp_id', $jobBatchId)
    //         ->get();

    //     if ($historyRecords->isEmpty()) {
    //         return response()->json(['message' => 'No applicant history found'], 404);
    //     }

    //     $first = $historyRecords->first();

    //     // ✅ Use internal or fallback to external
    //     $firstname = $first->internal_firstname ?? $first->external_firstname;
    //     $lastname  = $first->internal_lastname  ?? $first->external_lastname;
    //     $imageUrl  = null; // Initialize here to avoid "Undefined variable" error
    //     // $imagePath = $first->internal_image     ?? $first->external_image;

    //     // $imageUrl = $imagePath
    //     //     ? config('app.url') . '/storage/' . $imagePath
    //     //     : null;

    //     // ── Internal Applicant ───────────────────────────────────────────────────
    //     if ((!$firstname || !$lastname) &&   $first->ControlNo) {

    //         // ✅ Get personal info
    //         $active    = xPersonal::where('ControlNo', $first->ControlNo)->first();
    //         $pics      = $active->Pics ?? null;


    //         // ✅ Image
    //         if ($pics) {
    //             if (str_starts_with($pics, '\\\\') || str_starts_with($pics, '//')) {
    //                 $imageUrl = config('app.url') . '/api/employee/photo/' . $first->ControlNo;
    //             } elseif (filter_var($pics, FILTER_VALIDATE_URL)) {
    //                 $imageUrl = $pics;
    //             }
    //         }


    //     }
    //     // ── External Applicant (has nPersonalInfo_id) ────────────────────────────
    //     if ($first->nPersonalInfo_id) {
    //         $personalInfo = \App\Models\excel\nPersonal_info::find($first->nPersonalInfo_id);
    //         $rawImagePath = $personalInfo->image_path ?? null;

    //         if ($rawImagePath) {
    //             // Case 1: Already a valid HTTP URL (MinIO/storage)
    //             if (filter_var($rawImagePath, FILTER_VALIDATE_URL)) {
    //                 $imageUrl = $rawImagePath;
    //             }
    //             // Case 2: Local storage path
    //             elseif (Storage::disk('public')->exists($rawImagePath)) {
    //                 $imageUrl = config('app.url') . '/storage/' . $rawImagePath;
    //             }
    //         }
    //     }

    //     return response()->json([
    //         'applicant' => [
    //             'submission_id'    => (int)$first->submission_id,
    //             'nPersonalInfo_id' => (int)$first->nPersonalInfo_id,
    //             'ControlNo'        => $first->ControlNo,

    //             'firstname'        => $firstname,
    //             'lastname'         => $lastname,
    //             'image_url'        => $imageUrl,
    //         ],
    //         'history' => $historyRecords->map(fn($row) => [
    //             'id'          => $row->id,
    //             'rater_id'    => $row->rater_id,
    //             'rater_name'  => $row->rater_name,
    //             'education'   => $row->education,
    //             'experience'  => $row->experience,
    //             'training'    => $row->training,
    //             'performance' => $row->performance,
    //             'bei'         => $row->bei,
    //             'exam'         => $row->exam,
    //             'total_qs'    => $row->total_qs,
    //             'grand_total' => $row->grand_total,
    //             'ranking'     => $row->ranking,
    //         ]),
    //         'criteria' => $criteria,
    //     ]);
    // }

    public function applicantScoreDetials($applicantId, $jobBatchId)
    {
        $criteria = criteria_rating::with(['educations', 'trainings', 'experiences', 'performances', 'exams'])
            ->where('job_batches_rsp_id', $jobBatchId)->get();

        $historyRecords = rating_score::select(
            'rating_score.id',
            'rating_score.user_id as rater_id',
            'rater.name as rater_name',
            'rating_score.nPersonalInfo_id',
            'rating_score.ControlNo',
            'rating_score.job_batches_rsp_id',
            'rating_score.education_score as education',
            'rating_score.experience_score as experience',
            'rating_score.training_score as training',
            'rating_score.performance_score as performance',
            'rating_score.behavioral_score as bei',
            'rating_score.exam_score as exam',
            'rating_score.total_qs',
            'rating_score.grand_total',
            'rating_score.ranking',

            'nPersonalInfo.firstname as internal_firstname',
            'nPersonalInfo.lastname as internal_lastname',
            'nPersonalInfo.image_path as internal_image',

            'xPersonal.Firstname as external_firstname',
            'xPersonal.Surname as external_lastname',

            'submission.id as submission_id'
        )
            ->leftJoin('users as rater', 'rater.id', '=', 'rating_score.user_id')
            ->leftJoin('nPersonalInfo', 'nPersonalInfo.id', '=', 'rating_score.nPersonalInfo_id')
            ->leftJoin('xPersonal', 'xPersonal.ControlNo', '=', 'rating_score.ControlNo')
            ->leftJoin('xPersonalAddt', 'xPersonalAddt.ControlNo', '=', 'rating_score.ControlNo')
            ->leftJoin('submission', function ($join) {
                $join->on('submission.job_batches_rsp_id', '=', 'rating_score.job_batches_rsp_id')
                    ->where(function ($q) {
                        $q->on('submission.nPersonalInfo_id', '=', 'rating_score.nPersonalInfo_id')
                            ->orOn('submission.ControlNo', '=', 'rating_score.ControlNo');
                    });
            })
            ->where(function ($q) use ($applicantId) {
                $q->where('rating_score.nPersonalInfo_id', $applicantId)
                    ->orWhere('rating_score.ControlNo', $applicantId);
            })
            ->where('rating_score.job_batches_rsp_id', $jobBatchId)
            ->get();

        if ($historyRecords->isEmpty()) {
            return response()->json(['message' => 'No applicant history found'], 404);
        }

        $first = $historyRecords->first();

        $firstname = $first->internal_firstname ?? $first->external_firstname;
        $lastname  = $first->internal_lastname  ?? $first->external_lastname;
        $imageUrl  = null; // ✅ Initialize here to avoid "Undefined variable" error

        // ── Internal Applicant (has ControlNo) ──────────────────────────────────
        if ($first->ControlNo) {
            $active = xPersonal::where('ControlNo', $first->ControlNo)->first();
            $pics   = $active->Pics ?? null;

            if ($pics) {
                if (str_starts_with($pics, '\\\\') || str_starts_with($pics, '//')) {
                    $imageUrl = config('app.url') . '/api/employee/photo/' . $first->ControlNo;
                } elseif (filter_var($pics, FILTER_VALIDATE_URL)) {
                    $imageUrl = $pics;
                }
            }
        }

        // ── External Applicant (has nPersonalInfo_id) ────────────────────────────
        if ($first->nPersonalInfo_id) {
            $personalInfo = \App\Models\excel\nPersonal_info::find($first->nPersonalInfo_id);
            $rawImagePath = $personalInfo->image_path ?? null;

            if ($rawImagePath) {
                if (filter_var($rawImagePath, FILTER_VALIDATE_URL)) {
                    $imageUrl = $rawImagePath;
                } elseif (Storage::disk('public')->exists($rawImagePath)) {
                    $imageUrl = config('app.url') . '/storage/' . $rawImagePath;
                }
            }
        }

        return response()->json([
            'applicant' => [
                'submission_id'    => (int)$first->submission_id,
                'nPersonalInfo_id' => (int)$first->nPersonalInfo_id,
                'ControlNo'        => $first->ControlNo,
                'firstname'        => $firstname,
                'lastname'         => $lastname,
                'image_url'        => $imageUrl,
            ],
            'history' => $historyRecords->map(fn($row) => [
                'id'          => $row->id,
                'rater_id'    => $row->rater_id,
                'rater_name'  => $row->rater_name,
                'education'   => $row->education,
                'experience'  => $row->experience,
                'training'    => $row->training,
                'performance' => $row->performance,
                'bei'         => $row->bei,
                'exam'        => $row->exam,
                'total_qs'    => $row->total_qs,
                'grand_total' => $row->grand_total,
                'ranking'     => $row->ranking,
            ]),
            'criteria' => $criteria,
        ], 200, [], JSON_UNESCAPED_SLASHES); // remove slash /\/\/\ image
    }

    // applicant scores
    public function applicantFinalSummaryScore($jobpostId, $request)
    {
        $jobpost = JobBatchesRsp::findOrFail($jobpostId);

        $criteria = criteria_rating::with(['educations', 'trainings', 'experiences', 'performances', 'exams'])
            ->where('job_batches_rsp_id', $jobpostId)->get();

        $totalAssigned = Job_batches_user::where('job_batches_rsp_id', $jobpostId)
            ->whereHas('user', fn($q) => $q->where('active', 1))
            ->count();

        $totalCompleted = Job_batches_user::where('job_batches_rsp_id', $jobpostId)
            ->where('status', 'complete')
            ->count();

        $allScores = rating_score::select(
            'rating_score.id',
            'rating_score.user_id as rater_id',
            'users.name as rater_name',
            'users.role_type',
            'users.representative',
            'users.position',
            'rating_score.nPersonalInfo_id',
            'rating_score.ControlNo',
            'rating_score.job_batches_rsp_id',
            'rating_score.education_score as education',
            'rating_score.experience_score as experience',
            'rating_score.training_score as training',
            'rating_score.performance_score as performance',
            'rating_score.behavioral_score as bei',
            'rating_score.exam_score',
            'rating_score.grand_total',
            'nPersonalInfo.firstname',
            'nPersonalInfo.lastname',
            'submission.id as submission_id'
        )
            ->leftJoin('nPersonalInfo', 'nPersonalInfo.id', '=', 'rating_score.nPersonalInfo_id')
            ->leftJoin('users', 'users.id', '=', 'rating_score.user_id')
            ->leftJoin('submission', function ($join) {
                $join->on('submission.job_batches_rsp_id', '=', 'rating_score.job_batches_rsp_id')
                    ->whereColumn('submission.nPersonalInfo_id', 'rating_score.nPersonalInfo_id');
            })
            ->where('rating_score.job_batches_rsp_id', $jobpostId)
            ->get();

        // Get all unique raters for this job post (for column headers)
        $raters = $allScores->map(fn($r) => [
            'rater_id'   => $r->rater_id,
            'rater_name' => $r->rater_name,
            'position' => $r->position,
            'role_type' => $r->role_type,
            'representative' => $r->representative,
        ])->unique('rater_id')->values();

        // Group scores by applicant
        $scoresByApplicant = $allScores->groupBy(
            fn($row) => $row->nPersonalInfo_id ?: 'control_' . $row->ControlNo
        );

        $applicants = [];

        foreach ($scoresByApplicant as $applicantKey => $scoreRows) {
            $firstRow  = $scoreRows->first();
            $firstname = $firstRow->firstname;
            $lastname  = $firstRow->lastname;

            // Fallback for internal applicants
            if ((!$firstname || !$lastname) && $firstRow->ControlNo) {
                $active    = vwActive::where('ControlNo', $firstRow->ControlNo)->first();
                $firstname = $active->Firstname ?? $active->firstname ?? '';
                $lastname  = $active->Surname   ?? $active->surname   ?? '';
            }

            // ── Per-rater breakdown ──────────────────────────────────────────────
            $raterBreakdown = [];

            foreach ($scoreRows as $row) {
                $total_qs = (float)$row->education
                    + (float)$row->experience
                    + (float)$row->training
                    + (float)$row->performance;

                $raterBreakdown[] = [
                    'rater_id'   => $row->rater_id,
                    'rater_name' => $row->rater_name,
                    'education'  => number_format((float)$row->education,  2, '.', ''),
                    'experience' => number_format((float)$row->experience, 2, '.', ''),
                    'training'   => number_format((float)$row->training,   2, '.', ''),
                    'performance' => number_format((float)$row->performance, 2, '.', ''),
                    'total_qs'   => number_format($total_qs, 2, '.', ''),
                    'bei'        => $row->bei !== null ? number_format((float)$row->bei, 2, '.', '') : null,
                    'exam_score'       => $row->exam_score !== null ? number_format((float)$row->exam_score, 2, '.', '') : null,
                ];
            }

            // ── Averaged totals (uses your existing RatingService) ───────────────
            $scoresArray = $scoreRows->map(fn($row) => [
                'education'   => (float)$row->education,
                'experience'  => (float)$row->experience,
                'training'    => (float)$row->training,
                'performance' => (float)$row->performance,
                'bei'         => $row->bei,
                'exam_score'        => $row->exam_score,
            ])->toArray();

            $computed = RatingService::computeFinalScore($scoresArray);

            $applicants[] = [
                'nPersonalInfo_id' => (string)$firstRow->nPersonalInfo_id,
                'ControlNo'        => $firstRow->ControlNo,
                'submission_id'    => $firstRow->submission_id,
                'firstname'        => $firstname,
                'lastname'         => $lastname,

                // Per-rater scores
                'rater_scores'     => $raterBreakdown,

                // Averaged totals
                'total_rating'     => $computed['total_qs'],    // avg of all raters' total_qs
                'bei'              => $computed['bei'],          // avg of all raters' bei
                'exam_score'             => $computed['exam_score'],         // avg of all raters' exam
                'final_rating'     => $computed['grand_total'],  // total_rating + bei + exam

                // grand_total used for ranking
                'grand_total'      => $computed['grand_total'],
            ];
        }

        // Search filter
        $collection = collect($applicants);

        if (!empty($search)) {
            $collection = $collection->filter(function ($item) use ($search) {
                return str_contains(strtolower($item['firstname']), strtolower($search))
                    || str_contains(strtolower($item['lastname']),  strtolower($search))
                    || str_contains(strtolower((string)$item['ControlNo']), strtolower($search));
            })->values();
        }

        // Rank after filter
        $rankedApplicants = RatingService::addRanking($collection->values()->all());
        $collection       = collect($rankedApplicants);



        return response()->json([
            'jobpost_id'      => $jobpostId,
            'total_assigned'  => $totalAssigned,
            'total_completed' => $totalCompleted,
            'office' => $jobpost->Office ?? null,
            'position' => $jobpost->Position ?? null,
            'Salary_Grade' => $jobpost->SalaryGrade ?? null,
            'Plantilla_Item_No' => $jobpost->ItemNo ?? null,

            'criteria' => $criteria,

            // Rater headers (for frontend column generation)
            'raters'          => $raters,

            'data' => $collection,

        ]);
    }



    // applicant external photo
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
