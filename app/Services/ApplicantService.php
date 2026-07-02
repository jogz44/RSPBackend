<?php

namespace App\Services;

use App\Models\ApplicantExamScore;
use App\Models\criteria\criteria_rating;
use App\Models\excel\Children;
use App\Models\excel\Civil_service_eligibity;
use App\Models\excel\Education_background;
use App\Models\excel\Learning_development;
use App\Models\excel\nFamily;
use App\Models\excel\nPersonal_info;
use App\Models\excel\Personal_declarations;
use App\Models\excel\references;
use App\Models\excel\skill_non_academic;
use App\Models\excel\Voluntary_work;
use App\Models\excel\Work_experience;
use App\Models\Job_batches_user;
use App\Models\JobBatchesRsp;
use App\Models\rating_score;
use App\Models\Submission;
use App\Models\vwActive;
use App\Models\xPersonal;
use App\Models\xService;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    use ApiResponseTrait;

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

        if ($submission->ControlNo) {
            $internalImages = $this->getInternalPdsImage($submission->ControlNo);
            $internalData   = $internalImages->getData(true);

            $trainingImages    = $internalData['training_images']    ?? [];
            $educationImages   = $internalData['education_images']   ?? [];
            $eligibilityImages = $internalData['eligibility_images'] ?? [];
            $experienceImages  = $internalData['experience_images']  ?? [];
        }

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

    // fetch the applicant scores
    public function score($jobpostId, $request)
    {
        $search       = $request->input('search');
        $perPageInput = $request->input('per_page', 10);
        $currentPage  = $request->input('page', 1);

        $perPage = ($perPageInput === 'all') ? PHP_INT_MAX : (int) $perPageInput;

        $criteria = criteria_rating::with(['educations', 'trainings', 'experiences', 'performances', 'exams', 'behaviorals'])
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
            'rating_score.total_qs',
            'rating_score.grand_total',
            'rating_score.ranking',
            'nPersonalInfo.firstname',
            'nPersonalInfo.lastname',
            'nPersonalInfo.image_path',
            'submission.id as submission_id' // ✅ no trailing comma
        )
            ->leftJoin('nPersonalInfo', 'nPersonalInfo.id', '=', 'rating_score.nPersonalInfo_id')
            ->leftJoin('users', 'users.id', '=', 'rating_score.user_id')
            ->leftJoin('submission', function ($join) {
                $join->on('submission.job_batches_rsp_id', '=', 'rating_score.job_batches_rsp_id')
                    ->where(function ($q) {
                        $q->whereColumn('submission.nPersonalInfo_id', 'rating_score.nPersonalInfo_id')
                            ->orWhereColumn('submission.ControlNo', 'rating_score.ControlNo');
                    });
            })
            // ✅ No applicant_exam_scores join here — handled separately below
            ->where('rating_score.job_batches_rsp_id', $jobpostId)
            ->get();

        // ✅ Pre-load exam scores keyed by submission_id
        $examScores = \App\Models\ApplicantExamScore::whereHas('submission', function ($q) use ($jobpostId) {
            $q->where('job_batches_rsp_id', $jobpostId);
        })
            ->get()
            ->keyBy('submission_id');

        // Group by applicant
        $scoresByApplicant = $allScores->groupBy(fn($row) => $row->nPersonalInfo_id ?: 'control_' . $row->ControlNo);

        $applicants = [];

        foreach ($scoresByApplicant as $applicantKey => $scoreRows) {
            $firstRow = $scoreRows->first();

            $firstname = $firstRow->firstname;
            $lastname  = $firstRow->lastname;

            // fallback for internal applicants
            if ((!$firstname || !$lastname) && $firstRow->ControlNo) {
                $active    = xPersonal::where('ControlNo', $firstRow->ControlNo)->first();
                $firstname = $active->Firstname ?? $active->firstname ?? '';
                $lastname  = $active->Surname   ?? $active->surname  ?? '';
            }

            // ✅ Resolve exam_percentage directly from pre-loaded collection
            $examRecord      = !is_null($firstRow->submission_id) ? ($examScores[$firstRow->submission_id] ?? null) : null;
            $exam_percentage = $examRecord ? (float)$examRecord->exam_percentage : null;

            $scoresArray = $scoreRows->map(fn($row) => [
                'education'       => (float)$row->education,
                'experience'      => (float)$row->experience,
                'training'        => (float)$row->training,
                'performance'     => (float)$row->performance,
                'bei'             => $row->bei,
                'exam_percentage' => $exam_percentage, // ✅ same value for all rater rows
            ])->toArray();

            $computed = RatingService::computeFinalScore($scoresArray);

            $applicants[] = [
                'applicant_id'     => $firstRow->id,
                'nPersonalInfo_id' => (string)$firstRow->nPersonalInfo_id,
                'ControlNo'        => $firstRow->ControlNo,
                'jobpostId'        => (int)$firstRow->job_batches_rsp_id,
                'firstname'        => $firstname,
                'lastname'         => $lastname,
                'submission_id'    => $firstRow->submission_id,
            ] + $computed;
        }

        $collection = collect($applicants);

        if (!empty($search)) {
            $collection = $collection->filter(function ($item) use ($search) {
                return str_contains(strtolower($item['firstname']), strtolower($search)) ||
                    str_contains(strtolower($item['lastname']),  strtolower($search)) ||
                    str_contains(strtolower($item['ControlNo']), strtolower($search));
            })->values();
        }

        $rankedApplicants = RatingService::addRanking($collection->values()->all());

        $collection = collect($rankedApplicants);

        $total = $collection->count();

        $paginatedData = $collection->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return response()->json([
            'jobpost_id'      => $jobpostId,
            'total_assigned'  => $totalAssigned,
            'total_completed' => $totalCompleted,
            'criteria'        => $criteria,
            'data'            => $paginatedData,
            'meta' => [
                'current_page' => (int)$currentPage,
                'per_page'     => (int)$perPage,
                'total'        => $total,
                'last_page'    => ceil($total / $perPage),
            ],
        ]);
    }

    public function applicantScoreDetials($applicantId, $jobBatchId)
    {
        $criteria = criteria_rating::with(['educations', 'trainings', 'experiences', 'performances', 'exams', 'behaviorals'])
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
            'rating_score.total_qs',
            'rating_score.grand_total',
            'rating_score.ranking',

            'nPersonalInfo.firstname as internal_firstname',
            'nPersonalInfo.lastname as internal_lastname',
            'nPersonalInfo.image_path as internal_image',

            'xPersonal.Firstname as external_firstname',
            'xPersonal.Surname as external_lastname',

            'submission.id as submission_id'
            // ❌ removed applicant_exam_scores join from here
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

        // ✅ Resolve exam_percentage directly — works for both internal and external applicants
        // submission_id may be null if submission table has no ControlNo column (internal applicants)
        // so we look up submission separately if needed
        $submissionId = $first->submission_id;

        if (!$submissionId) {
            // Try resolving submission_id for internal applicants via ControlNo
            $submissionId = \App\Models\Submission::where('job_batches_rsp_id', $jobBatchId)
                ->where('ControlNo', $first->ControlNo)
                ->value('id');
        }

        $examPercentage = null;
        if ($submissionId) {
            $examPercentage = \App\Models\ApplicantExamScore::where('submission_id', $submissionId)
                ->value('exam_percentage');
        }

        $firstname = $first->internal_firstname ?? $first->external_firstname;
        $lastname  = $first->internal_lastname  ?? $first->external_lastname;
        $imageUrl  = null;

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
                'submission_id'    => $submissionId ? (int)$submissionId : null,
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
                // ✅ Same exam_percentage for all rater rows — it's per applicant, not per rater
                'exam'        => $examPercentage !== null ? (float)$examPercentage : null,
                'total_qs'    => $row->total_qs,
                'grand_total' => $row->grand_total,
                'ranking'     => $row->ranking,
            ]),
            'criteria' => $criteria,
        ], 200, [], JSON_UNESCAPED_SLASHES);
    }

    // applicant scores
    public function applicantFinalSummaryScore($jobpostId, $request)
    {
        $jobpost = JobBatchesRsp::findOrFail($jobpostId);

        $criteria = criteria_rating::with(['educations', 'trainings', 'experiences', 'performances', 'exams', 'behaviorals'])
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
            'users.prefix',
            'users.suffix',
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
            // ❌ removed rating_score.exam_score
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

        // ✅ Pre-load exam scores keyed by submission_id
        $examScores = \App\Models\ApplicantExamScore::whereHas('submission', function ($q) use ($jobpostId) {
            $q->where('job_batches_rsp_id', $jobpostId);
        })
            ->get()
            ->keyBy('submission_id');

        // Get all unique raters for this job post (for column headers)
        $raters = $allScores->map(fn($r) => [
            'rater_id'       => $r->rater_id,
            'rater_name'     => $r->rater_name,
            'position'       => $r->position,
            'role_type'      => $r->role_type,
            'representative' => $r->representative,
            'prefix'         => $r->prefix,
            'suffix'         => $r->suffix,
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

            // ✅ Resolve exam_score once per applicant from ApplicantExamScore
            // For internal applicants (no submission_id from join), fall back to ControlNo lookup
            $submissionId = $firstRow->submission_id;

            if (!$submissionId && $firstRow->ControlNo) {
                $submissionId = \App\Models\Submission::where('job_batches_rsp_id', $jobpostId)
                    ->where('ControlNo', $firstRow->ControlNo)
                    ->value('id');
            }

            $examRecord = $submissionId ? ($examScores[$submissionId] ?? null) : null;
            $examScore  = $examRecord ? (float)$examRecord->exam_percentage : null;

            // ── Per-rater breakdown ──────────────────────────────────────────────
            $raterBreakdown = [];

            foreach ($scoreRows as $row) {
                $total_qs = (float)$row->education
                    + (float)$row->experience
                    + (float)$row->training
                    + (float)$row->performance;

                $raterBreakdown[] = [
                    'rater_id'    => $row->rater_id,
                    'rater_name'  => $row->rater_name,
                    'education'   => number_format((float)$row->education,   2, '.', ''),
                    'experience'  => number_format((float)$row->experience,  2, '.', ''),
                    'training'    => number_format((float)$row->training,    2, '.', ''),
                    'performance' => number_format((float)$row->performance, 2, '.', ''),
                    'total_qs'    => number_format($total_qs,                2, '.', ''),
                    'bei'         => $row->bei !== null ? number_format((float)$row->bei, 2, '.', '') : null,
                    // ✅ exam_score is applicant-level, same value across all rater rows
                    'exam_score'  => $examScore !== null ? number_format($examScore, 2, '.', '') : null,
                ];
            }

            // ── Averaged totals ──────────────────────────────────────────────────
            $scoresArray = $scoreRows->map(fn($row) => [
                'education'   => (float)$row->education,
                'experience'  => (float)$row->experience,
                'training'    => (float)$row->training,
                'performance' => (float)$row->performance,
                'bei'         => $row->bei,
                // ✅ Pass resolved exam_percentage as exam_score into RatingService
                'exam_percentage' => $examScore, // ✅ matches RatingService key

            ])->toArray();

            $computed = RatingService::computeFinalScore($scoresArray);

            $applicants[] = [
                'nPersonalInfo_id' => (string)$firstRow->nPersonalInfo_id,
                'ControlNo'        => $firstRow->ControlNo,
                'submission_id'    => $submissionId,
                'firstname'        => $firstname,
                'lastname'         => $lastname,

                // Per-rater scores
                'rater_scores'     => $raterBreakdown,

                // Averaged totals
                'total_rating'     => $computed['total_qs'],
                'bei'              => $computed['bei'],
                'exam_score'      => $examScore, // ✅ must match what RatingService reads
                'final_rating'     => $computed['grand_total'],
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
            'jobpost_id'        => $jobpostId,
            'total_assigned'    => $totalAssigned,
            'total_completed'   => $totalCompleted,
            'office'            => $jobpost->Office ?? null,
            'position'          => $jobpost->Position ?? null,
            'Salary_Grade'      => $jobpost->SalaryGrade ?? null,
            'Plantilla_Item_No' => $jobpost->ItemNo ?? null,
            'criteria'          => $criteria,
            'raters'            => $raters,
            'data'              => $collection,
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


    // EDIT THE APPLICANT QS
    public function qsEdit($validated)
    {
        DB::transaction(function () use ($validated) {

            // --- Personal Information ---
            if (isset($validated['personal_info_id'])) {
                nPersonal_info::where('id', $validated['personal_info_id'])
                    ->update(collect($validated)->only([
                        'lastname',
                        'firstname',
                        'middlename',
                        'name_extension',
                        'date_of_birth',
                        'sex',
                        'place_of_birth',
                        'height',
                        'weight',
                        'blood_type',
                        'gsis_no',
                        'pagibig_no',
                        'philhealth_no',
                        'sss_no',
                        'tin_no',
                        'civil_status',
                        'citizenship',
                        'citizenship_status',
                        'telephone_number',
                        'cellphone_number',
                        'email_address',
                        'agency_employee_no',
                        'umId',
                        'philSys',
                        'pwd',
                        'gender_prefer',
                        'other_specify',
                        'image_path',
                        'residential_house',
                        'residential_street',
                        'residential_subdivision',
                        'residential_barangay',
                        'residential_city',
                        'residential_province',
                        'residential_zip',
                        'Rpurok',
                        'permanent_house',
                        'permanent_street',
                        'permanent_subdivision',
                        'permanent_barangay',
                        'permanent_city',
                        'permanent_province',
                        'permanent_zip',
                        'Ppurok',
                    ])->toArray());
            }

            // --- Family ---
            if (isset($validated['family_id'])) {
                nFamily::where('id', $validated['family_id'])
                    ->update(collect($validated)->only([
                        'spouse_name',
                        'spouse_firstname',
                        'spouse_middlename',
                        'spouse_extension',
                        'spouse_occupation',
                        'spouse_employer',
                        'spouse_employer_address',
                        'spouse_employer_telephone',
                        'father_lastname',
                        'father_firstname',
                        'father_middlename',
                        'father_extension',
                        'mother_lastname',
                        'mother_firstname',
                        'mother_middlename',
                        'mother_maidenname',
                    ])->toArray());
            }
            // --- personal declarations ---
            if (isset($validated['personal_declaration_id'])) {
                Personal_declarations::where('id', $validated['personal_declaration_id'])
                    ->update(collect($validated)->only([
                        // Q34

                        'question_34a',

                        'question_34b',
                        'response_34', // resoon

                        // Q35
                        'question_35a',
                        'response_35a', //reason

                        'question_35b',
                        'response_35b_date', //reason
                        'response_35b_status', //reason

                        // Q36
                        'question_36',
                        'response_36', //reason

                        // Q37
                        'question_37',
                        'response_37', //reason

                        // Q38
                        'question_38a',
                        'response_38a', //reason

                        'question_38b',
                        'response_38b', //reason

                        // Q39
                        'question_39',
                        'response_39', //reason

                        // Q40
                        'question_40a',
                        'response_40a', //reason

                        'question_40b',
                        'response_40b', //reason

                        'question_40c',
                        'response_40c', //reason

                        'chronic',
                        'Psychosocial',
                        'Orthopedic',
                        'Communication',
                        'Learning',
                        'Mental',
                        'Visual',

                    ])->toArray());
            }

            // --- Children (loop through array) ---
            if (isset($validated['children'])) {
                foreach ($validated['children'] as $child) {
                    if (isset($child['id'])) {
                        Children::where('id', $child['id'])
                            ->update(collect($child)->except('id')->toArray());
                    } else {
                        Children::create(array_merge($child, [
                            'nPersonalInfo_id' => $validated['personal_info_id']
                        ]));
                    }
                }
            }

            // --- Eligibility ---
            if (isset($validated['eligibilities'])) {
                foreach ($validated['eligibilities'] as $item) {
                    if (isset($item['id'])) {
                        Civil_service_eligibity::where('id', $item['id'])
                            ->update(collect($item)->except('id')->toArray());
                    } else {
                        Civil_service_eligibity::create(array_merge($item, [
                            'nPersonalInfo_id' => $validated['personal_info_id']
                        ]));
                    }
                }
            }


            // --- Education ---

            if (isset($validated['educations'])) {
                foreach ($validated['educations'] as $item) {
                    if (isset($item['id'])) {
                        Education_background::where('id', $item['id'])
                            ->update(collect($item)->except('id')->toArray());
                    } else {
                        Education_background::create(array_merge($item, [
                            'nPersonalInfo_id' => $validated['personal_info_id']
                        ]));
                    }
                }
            }

            // --- Training ---
            if (isset($validated['trainings'])) {
                foreach ($validated['trainings'] as $item) {
                    if (isset($item['id'])) {
                        Learning_development::where('id', $item['id'])
                            ->update(collect($item)->except('id')->toArray());
                    } else {
                        Learning_development::create(array_merge($item, [
                            'nPersonalInfo_id' => $validated['personal_info_id']
                        ]));
                    }
                }
            }

            // --- Skills ---
            if (isset($validated['skills'])) {
                foreach ($validated['skills'] as $item) {
                    if (isset($item['id'])) {
                        skill_non_academic::where('id', $item['id'])
                            ->update(collect($item)->except('id')->toArray());
                    } else {
                        skill_non_academic::create(array_merge($item, [
                            'nPersonalInfo_id' => $validated['personal_info_id']
                        ]));
                    }
                }
            }

            // --- Voluntary Work ---
            if (isset($validated['voluntary_works'])) {
                foreach ($validated['voluntary_works'] as $item) {
                    if (isset($item['id'])) {
                        Voluntary_work::where('id', $item['id'])
                            ->update(collect($item)->except('id')->toArray());
                    } else {
                        Voluntary_work::create(array_merge($item, [
                            'nPersonalInfo_id' => $validated['personal_info_id']
                        ]));
                    }
                }
            }
            // --- Work Experience ---
            if (isset($validated['work_experiences'])) {
                foreach ($validated['work_experiences'] as $item) {
                    if (isset($item['id'])) {
                        Work_experience::where('id', $item['id'])
                            ->update(collect($item)->except('id')->toArray());
                    } else {
                        Work_experience::create(array_merge($item, [
                            'nPersonalInfo_id' => $validated['personal_info_id']
                        ]));
                    }
                }
            }


            // --- references ---
            if (isset($validated['references'])) {
                foreach ($validated['references'] as $item) {
                    if (isset($item['id'])) {
                        references::where('id', $item['id'])
                            ->update(collect($item)->except('id')->toArray());
                    } else {
                        references::create(array_merge($item, [
                            'nPersonalInfo_id' => $validated['personal_info_id']
                        ]));
                    }
                }
            }
        });

        return response()->json(['message' => 'Updated successfully'], 200);
    }




    // get the internalPds
    function getInternalPdsImage($controlNo)
    {
        $training    = $this->getTrainingImage($controlNo);
        $education   = $this->getEducationImage($controlNo);
        $experience  = $this->getExperienceImage($controlNo);
        $eligibility = $this->getEligibilityImage($controlNo);

        $baseUrl = config('app.network_share_img_pds.base_url'); //
        $buildUrls = function ($records) use ($baseUrl) {
            return collect($records)
                ->filter(fn($record) => !empty($record->img))
                ->map(fn($record) => config('app.url') . '/api/applicant/pds-image/' . $record->img)
                ->values();
        };
        return response()->json([
            'control_no'          => $controlNo,
            'training_images'     => $buildUrls($training),
            'education_images'    => $buildUrls($education),
            'experience_images'   => $buildUrls($experience),
            'eligibility_images'  => $buildUrls($eligibility),
        ]);
    }



    // training images — all records
    private function getTrainingImage($controlNo)
    {
        return DB::table('tblPDSUpdatesTrainings')
            ->select('ID', 'Controlno', 'Training', 'img', 'status')
            ->where('Controlno', $controlNo)
            ->where('status', 'ACCEPTED')
            ->get();
    }

    // education images — all records
    private function getEducationImage($controlNo)
    {
        return DB::table('tblPDSUpdatesEducation')
            ->select('ID', 'ControlNo', 'School', 'img', 'status')
            ->where('ControlNo', $controlNo)
            ->where('status', 'ACCEPTED')
            ->get();
    }

    // experience images — all records
    private function getExperienceImage($controlNo)
    {
        return DB::table('tblPDSUpdatesWorkExperience')
            ->select('ID', 'controlno', 'Wposition', 'img', 'status')
            ->where('ControlNo', $controlNo)
            ->where('status', 'ACCEPTED')
            ->get();
    }

    // eligibility images — all records
    private function getEligibilityImage($controlNo)
    {
        return DB::table('tblPDSUpdatesCivilService')
            ->select('ID', 'controlno', 'CivilServe', 'img', 'status')
            ->where('ControlNo', $controlNo)
            ->where('status', 'ACCEPTED')
            ->get();
    }

    // // proxy using my app url
    // public function proxyPdsImage($filename)
    // {
    //     $baseUrl = config('app.network_share_img_pds.base_url'); // 
    //     $imageUrl = "{$baseUrl}/{$filename}";

    //     $response = Http::get($imageUrl);

    //     if (!$response->successful()) {
    //         return response()->json(['message' => 'Image not found'], 404);
    //     }

    //     return response($response->body(), 200)
    //         ->header('Content-Type', $response->header('Content-Type'));
    // }

    // list of qualified applicants  for job post publication




    // working  applicant list 
    //  public function listOfApplicants()
    // {
    //     try {
    //     $external = Submission::query()
    //     ->join('nPersonalInfo as p', 'submission.nPersonalInfo_id', '=', 'p.id')
    //     ->select(
    //         DB::raw('MIN(p.id) as nPersonal_id'),
    //         'p.firstname',
    //         'p.lastname',
    //         DB::raw('TRY_CAST(p.date_of_birth AS DATE) as date_of_birth'), // ✅ TRY_CAST
    //         DB::raw('COUNT(submission.id) as jobpost'),
    //         DB::raw("'external' as applicant_type"),
    //         DB::raw('NULL as ControlNo')
    //     )
    //     ->groupBy('p.firstname', 'p.lastname', 'p.date_of_birth');

    // $internal = Submission::query()
    //     ->whereNull('submission.nPersonalInfo_id')
    //     ->join('xPersonal as xp', 'submission.ControlNo', '=', 'xp.ControlNo')
    //     ->select(
    //         DB::raw('NULL as nPersonal_id'),
    //         'xp.Firstname as firstname',
    //         'xp.Surname as lastname',
    //         DB::raw('TRY_CAST(xp.BirthDate AS DATE) as date_of_birth'), // ✅ TRY_CAST
    //         DB::raw('COUNT(submission.id) as jobpost'),
    //         DB::raw("'internal' as applicant_type"),
    //         'submission.ControlNo'
    //     )
    //     ->groupBy('xp.Firstname', 'xp.Surname', 'xp.BirthDate', 'submission.ControlNo');

    //         $query = $external->unionAll($internal);

    //         $schedule = DB::table(DB::raw("({$query->toSql()}) as combined"))
    //             ->mergeBindings($query->getQuery())
    //             ->get();

    //         return response()->json($schedule);

    //     } catch (\Illuminate\Database\QueryException $e) {
    //         return response()->json([
    //             'success'  => false,
    //             'message'  => $e->getMessage(),
    //             'sql'      => $e->getSql(),
    //             'bindings' => $e->getBindings(),
    //             'file'     => $e->getFile(),
    //             'line'     => $e->getLine(),
    //         ], 500);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => $e->getMessage(),
    //             'file'    => $e->getFile(),
    //             'line'    => $e->getLine(),
    //         ], 500);
    //     }
    // }

    // fetch the  applicant list applied
    public function applicantApplied($postDate, ?string $applicantType = null)
    {
         ini_set('max_execution_time', 3600);
        try {
            // ── 1. Parse the incoming date (handles "April 27, 2026" or "2026-04-27") ──
            $parsedDate = \Carbon\Carbon::parse($postDate)->format('Y-m-d');

            // ── 2. Get all job post IDs for that post_date ────────────────────────────
            $jobPostIds = JobBatchesRsp::whereDate('post_date', $parsedDate)
                ->pluck('id');

            if ($jobPostIds->isEmpty()) {

                return $this->infoMessage('No job posts found for the given date', 200);
            }

            // ── 3. Get all submissions for those job posts ────────────────────────────
            $submissions = Submission::select(
                'id',
                'nPersonalInfo_id',
                'ControlNo',
                'job_batches_rsp_id',
                'status'
            )
                ->whereIn('job_batches_rsp_id', $jobPostIds)
                ->when($applicantType === 'internal', fn($q) => $q->whereNotNull('ControlNo')->whereNull('nPersonalInfo_id'))
                ->when($applicantType === 'external', fn($q) => $q->whereNotNull('nPersonalInfo_id')->whereNull('ControlNo'))
                ->with([
                    'nPersonalInfo:id,firstname,lastname,date_of_birth,cellphone_number,email_address',
                    'jobPost:id,Position,Office,SalaryGrade,ItemNo,status',
                ])
                ->get();


            // ── 4. Normalize each submission into a flat structure ────────────────────
            $normalized = $submissions->map(function ($submission) {
                $isExternal = ! is_null($submission->nPersonalInfo_id);

                if ($isExternal && $submission->nPersonalInfo) {
                    $info = [
                        'id'            => $submission->nPersonalInfo->id,
                        'firstname'     => trim($submission->nPersonalInfo->firstname),
                        'lastname'      => trim($submission->nPersonalInfo->lastname),
                        'date_of_birth' => $submission->nPersonalInfo->date_of_birth, // keep raw for grouping

                        'cellphone_number' => $submission->nPersonalInfo->cellphone_number,  // ← add
                        'email_address'    => $submission->nPersonalInfo->email_address,
                    ];
                } elseif (! $isExternal && $submission->xPersonal) {
                    $info = [
                        'id'            => $submission->xPersonal->id,  // adjust PK name
                        'firstname'     => trim($submission->xPersonal->Firstname),
                        'lastname'      => trim($submission->xPersonal->Surname),
                        'date_of_birth' => $submission->xPersonal->BirthDate,
                        'email_address' => $submission->xPersonalAddt->EmailAdd,
                        'cellphone_number' => $submission->xPersonalAddt->CellphoneNo,
                    ];
                } else {
                    $info = null;
                }

                return [
                    'submission_id'         => $submission->id,
                    'nPersonalInfo_id'      => $submission->nPersonalInfo_id,
                    'ControlNo'             => $submission->ControlNo,
                    'job_batches_rsp_id'    => $submission->job_batches_rsp_id,
                    'applicant_status'      => $submission->status,
                    'applicant_type'        => $isExternal ? 'external' : 'internal',
                    'personal_info'         => $info,
                    'job_post'              => $submission->jobPost,
                ];
            })->filter(fn($s) => ! is_null($s['personal_info'])); // skip if no personal info found

            // ── 5. Group by person: lowercase name + normalized birthdate ─────────────
            $grouped = $normalized->groupBy(function ($item) {
                $firstname = strtolower(trim($item['personal_info']['firstname']));
                $lastname  = strtolower(trim($item['personal_info']['lastname']));

                // Normalize date to Y-m-d regardless of source format
                try {
                    $dob = \Carbon\Carbon::parse($item['personal_info']['date_of_birth'])->format('Y-m-d');
                } catch (\Exception $e) {
                    $dob = $item['personal_info']['date_of_birth'];
                }

                return "{$firstname}|{$lastname}|{$dob}";
            });

            // ── 6. Build the final response ───────────────────────────────────────────
            // ── 6. Build the final response ───────────────────────────────────────────
            $result = $grouped->values()->map(function ($group) {
                $first      = $group->first();
                $personInfo = $first['personal_info'];

                try {
                      $dobCarbon    = \Carbon\Carbon::parse($personInfo['date_of_birth']);
                    $dobFormatted = \Carbon\Carbon::parse($personInfo['date_of_birth'])->format('d/m/Y');
                       $dobFormatted = $dobCarbon->format('d/m/Y');
    $age            = $dobCarbon->age;
                } catch (\Exception $e) {
                    $dobFormatted = $personInfo['date_of_birth'];
                     $age          = null;
                }

                $applications = $group->map(function ($submission) {
                    $jp = $submission['job_post'];
              
                $xservice  = xService::select('FromDate', 'ToDate')->where('ControlNo',  $submission['ControlNo'])->get();
                    $totalDays = 0;
                    $today     = Carbon::now();

                       foreach ($xservice as $service) {
                    $from = Carbon::parse($service->FromDate);
                    $to   = Carbon::parse($service->ToDate ?? now());

                    if ($to->isFuture())   $to   = $today;
                    if ($from->isFuture()) continue;

                    $totalDays += $from->diffInDays($to);
                }

                   $years  = intdiv($totalDays, 365);
                $remain = $totalDays % 365;
                $months = intdiv($remain, 30);
                $days   = $remain % 30;

                $lengthOfService = "{$years} years, {$months} months, {$days} days";

                    return [
                        'submission_id'      => $submission['submission_id'],
                        'nPersonalInfo_id'   => $submission['nPersonalInfo_id'],
                        'ControlNo'          => $submission['ControlNo'],
                        'job_batches_rsp_id' => $submission['job_batches_rsp_id'],
                        'job_post'           => $jp ? [
                            'id'          => $jp->id,
                            'Position'    => $jp->Position,
                            'Office'      => $jp->Office,
                            'SalaryGrade' => $jp->SalaryGrade,
                            'ItemNo'      => $jp->ItemNo,
                        ] : null,
                        'applicant_status'   => $submission['applicant_status'],
                        'applicant_type'     => $submission['applicant_type'],
                        'lengthOfService' => $lengthOfService,
                    ];
                })->values();

                // Determine type from the first application
                $applicantType = $first['applicant_type'];

                return [
                    'applicant_type' => $applicantType,
                    'applicant' => [
                        'id'                    => $personInfo['id'],
                        'firstname'             => $personInfo['firstname'],
                        'lastname'              => $personInfo['lastname'] ?? null,
                        'date_of_birth'         => $dobFormatted ?? null,
                        'age'         => Carbon::createFromFormat('d/m/Y', $dobFormatted)->age,
                        'cellphone_number'      => $personInfo['cellphone_number'] ?? null,
                        'email_address'         => $personInfo['email_address'] ?? null,
                        'applicant_application' => $applications,
                    ],
                ];
            });

            if ($result->isEmpty()) {
                return $this->infoMessage('No applicants found for the given date');
            }
            // ── 7. Separate into external and internal ────────────────────────────────
            $external = $result->filter(fn($item) => $item['applicant_type'] === 'external')
                ->map(fn($item) => $item['applicant'])
                ->sortBy(fn($applicant) => strtolower($applicant['lastname'])) // sort A-Z by lastname
                ->values();

            $internal = $result->filter(fn($item) => $item['applicant_type'] === 'internal')
                ->map(fn($item) => $item['applicant'])
                ->sortBy(fn($applicant) => strtolower($applicant['lastname'])) // sort A-Z by lastname
                ->values();


            return $this->successMessage([
                'post_date' =>  Carbon::parse($parsedDate)->format('F d, Y'),
                'data'      => [
                    'external' => $external,
                    'internal' => $internal,
                ],
            ], 'Applicants retrieved successfully.');
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'success'  => false,
                'message'  => $e->getMessage(),
                'sql'      => $e->getSql(),
                'bindings' => $e->getBindings(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ], 500);
        }
    }

    // list of applicant where qualified
    public function ApplicantQualified($postDate)
    {
        try {
            $postDate = Carbon::parse($postDate)->toDateString();

            $jobPosts = JobBatchesRsp::whereDate('post_date', $postDate)
                ->select('id', 'Position', 'ItemNo', 'SalaryGrade', 'Office')
                ->get();

            if ($jobPosts->isEmpty()) {
                return response()->json(['message' => 'No job posts found for this date.'], 200);
            }

            $jobPostIds = $jobPosts->pluck('id');

            // Fetch all qualified submissions
            $allSubmissions = DB::table('submission')
                ->whereIn('job_batches_rsp_id', $jobPostIds)
                ->where('status', 'Qualified')
                ->select('id', 'job_batches_rsp_id', 'nPersonalInfo_id', 'ControlNo', 'status', 'tag_color')
                ->get();

            // Split into external and internal
            $externalSubs = $allSubmissions->whereNotNull('nPersonalInfo_id');
            $internalSubs = $allSubmissions->whereNotNull('ControlNo')->where('nPersonalInfo_id', null);

            // Group all submissions by job post
            $submissionsByJob = $allSubmissions->groupBy('job_batches_rsp_id');

            // Fetch external applicant info
            $nPersonalInfoIds = $externalSubs->pluck('nPersonalInfo_id')->unique();
            $nPersonalInfos = DB::table('nPersonalInfo')
                ->whereIn('id', $nPersonalInfoIds)
                ->select('id', 'firstname', 'lastname')
                ->get()
                ->keyBy('id');

            // Fetch internal applicant info (chunked for large sets)
            $controlNos = $internalSubs->pluck('ControlNo')->unique()->values()->toArray();
            $xPersonals = DB::table('xPersonal')
                ->whereIn('ControlNo', $controlNos)
                ->select('ControlNo', 'Firstname', 'Surname')
                ->get()
                ->keyBy('ControlNo');

            // Build response
            $responseApplicants = [];

            foreach ($jobPosts as $job) {


                foreach ($submissionsByJob->get($job->id, collect()) as $submission) {

                    $base = [
                        'Office'      => $office->Descriptions ?? $job->Office,
                        'Position'    => $job->Position,
                        'ItemNo'      => $job->ItemNo,
                        'SalaryGrade' => $job->SalaryGrade,
                    ];

                    // External
                    if (!empty($submission->nPersonalInfo_id)) {
                        $personalInfo = $nPersonalInfos->get($submission->nPersonalInfo_id);
                        if (!$personalInfo) continue;

                        $responseApplicants[] = array_merge([
                            'submission_id'        => $submission->id,
                            'firstname'        => $personalInfo->firstname,
                            'lastname'         => $personalInfo->lastname,
                            'status'           => $submission->status,
                            'applicant_status' => 'EXTERNAL',
                            'tag_color' => $submission->tag_color,
                        ], $base);
                    }

                    // Internal
                    elseif (!empty($submission->ControlNo)) {
                        $personal = $xPersonals->get($submission->ControlNo);
                        if (!$personal) continue;

                        $responseApplicants[] = array_merge([
                            'submission_id'        => $submission->id,
                            'controlno'        => $submission->ControlNo,
                            'firstname'        => $personal->Firstname,
                            'lastname'         => $personal->Surname,
                            'status'           => $submission->status,
                            'applicant_status' => 'INTERNAL',
                            'tag_color' => $submission->tag_color,
                        ], $base);
                    }
                }
            }

            $payload = [
                'Header'     => 'Applicants Qualified Standard',
                'Date'       => Carbon::parse($postDate)->format('F d, Y') . ' Publication',
                'applicants' => $responseApplicants,
            ];

            return response()->json($payload, 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ], 500);
        }
    }
}
