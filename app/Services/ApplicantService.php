<?php

namespace App\Services;

use App\Models\JobBatchesRsp;
use App\Models\rating_score;
use App\Models\Submission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
    public function store($validated,$request)
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


    // get the pdf of employee
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
                'image_path' => $employeeJson['User'][0]['Pics'] ?? $employeeJson['User'][0]['image_path'] ?? null,
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
        if ($info && isset($info['image_path']) && $info['image_path']) {
            if (Storage::disk('public')->exists($info['image_path'])) {
                $baseUrl = config('app.url');
                $imageUrl = $baseUrl . '/storage/' . $info['image_path'];
            }
        }
        $trainingImages = [];
        $educationImages = [];
        $eligibilityImages = [];
        $experienceImages = [];

        if ($info && isset($info['id'])) {
            $baseFolder = storage_path('app/public/applicant_files/' . $submission->nPersonalInfo_id);

            $folders = [
                'training' => $baseFolder . '/document/training',
                'education' => $baseFolder . '/document/education',
                'eligibility' => $baseFolder . '/document/eligibility',
                'experience' => $baseFolder . '/document/experience',
            ];

            foreach ($folders as $type => $path) {
                if (is_dir($path)) {
                    $files = collect(scandir($path))
                        ->filter(fn($file) => !in_array($file, ['.', '..']))
                        ->map(fn($file) => asset('storage/applicant_files/' . $info['id'] . '/document/' . $type . '/' . $file))
                        ->values()
                        ->toArray();

                    if ($type === 'training') $trainingImages = $files;
                    if ($type === 'education') $educationImages = $files;
                    if ($type === 'eligibility') $eligibilityImages = $files;
                    if ($type === 'experience') $experienceImages = $files;
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
            'training_images' => $trainingImages,
            'education_images' => $educationImages,
            'eligibility_images' => $eligibilityImages,
            'experience_images' => $experienceImages,
            'ranking' => $rating->ranking ?? null,
        ]);
    }
}
