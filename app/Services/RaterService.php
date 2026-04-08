<?php

namespace App\Services;

use App\Http\Requests\RatersRegisterRequest;
use App\Models\criteria\criteria_rating;
use App\Models\draft_score;
use App\Models\JobBatchesRsp;
use App\Models\rating_score;
use App\Models\Submission;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RaterService
{
    /**
     * Create a new class instance.
     */
    // public function __construct()
    // {
    //     //
    // }


    //create account and register rater account
    public function create($validated, $request)
    {
        $authUser = Auth::user(); // The currently logged-in admin (who is creating the rater)

        // Create the new rater user
        $rater = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'position' => $validated['position'],
            'office' => $validated['office'],
            'password' => Hash::make($validated['password']),
            'active' => true, // Always set new raters as active
            'role_id' => 2,   // 2 = Rater
            'remember_token' => Str::random(32),
            'must_change_password' => true, // ← Force password change
            'role_type' => $validated['role'],
            'representative' => $validated['representative'],
        ]);

        // Attach job batches
        $rater->job_batches_rsp()->attach($validated['job_batches_rsp_id']);

        // ✅ Log the activity using Spatie Activity Log
        activity('Create')
            ->causedBy($authUser)               // The admin who created the rater
            ->performedOn($rater)               // The new rater account created
            ->withProperties([
                'created_by' => $authUser?->name,
                'new_rater_name' => $rater->name,
                'username' => $rater->username,
                'position' => $rater->position,
                'office' => $rater->office,
                'role' => 'Rater',
                'assigned_job_batches' => $validated['job_batches_rsp_id'],
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ])
            ->log("Rater {$rater->name} was Created successfully by '{$authUser?->name}'.");

        return response()->json([
            'status' => true,
            'message' => 'Rater Registered Successfully',
            'data' => $rater->load('job_batches_rsp'),
        ], 201);
    }

    // update rater
    public function update($validated, $id, $request)
    {
        $authUser = Auth::user(); // The admin who performs the update


        // Find the user (rater) by ID
        $rater = User::findOrFail($id);

        // Keep old values for logging comparison
        $oldData = [
            'office' => $rater->office,
            'active' => $rater->active,
            'job_batches_rsp_id' => $rater->job_batches_rsp()->pluck('job_batches_rsp.id')->toArray(),

        ];

        // Update new values
        $rater->update([
            'office' => $validated['office'],
            'active' => $validated['active'],
            'role_type' => $validated['role_type'] ?? null,
            'representative' => $validated['representative'] ?? null,
        ]);

        if (isset($validated['job_batches_rsp_id'])) {
            $newJobPosts = collect($validated['job_batches_rsp_id']);

            foreach ($newJobPosts as $jobPostId) {
                // Check if pivot already exists
                $pivot = \App\Models\Job_batches_user::where('user_id', $rater->id)
                    ->where('job_batches_rsp_id', $jobPostId)
                    ->first();

                if ($pivot) {
                    // ✅ Keep existing status (complete or pending)
                    continue;
                } else {
                    // 🆕 New assignment, default to pending
                    \App\Models\Job_batches_user::create([
                        'user_id' => $rater->id,
                        'job_batches_rsp_id' => $jobPostId,
                        'status' => 'pending',
                    ]);
                }
            }

            // Optional: Remove assignments that are no longer selected but preserve completed ones
            $rater->job_batches_rsp()
                ->wherePivotNotIn('job_batches_rsp_id', $newJobPosts)
                ->wherePivot('status', '!=', 'complete')
                ->detach();
        }


        // Load updated relations
        $rater->load('job_batches_rsp');

        // ✅ Log the update activity
        activity('Update')
            ->causedBy($authUser)                // The admin who made the change
            ->performedOn($rater)                // The rater whose account was edited
            ->withProperties([
                'updated_by' => $authUser?->name,
                'rater_name' => $rater->name,
                'rater_username' => $rater->username,
                'old_data' => $oldData,
                'new_data' => [
                    'office' => $rater->office,
                    'active' => $rater->active,
                    'job_batches_rsp_id' => $validated['job_batches_rsp_id'] ?? $oldData['job_batches_rsp_id'],
                ],
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ])
            ->log("Rater {$rater->name} was updated by '{$authUser?->name}'.");

        return response()->json([
            'status' => true,
            'message' => 'Rater Updated Successfully',
            'data' => $rater,
        ]);
    }



    // login function for rater
    public function login($request)
    {
        // First check if username and password are provided
        if (empty($request->username) || empty($request->password)) {
            return response([
                'status' => false,
                'message' => 'Invalid Credentials',
                'errors' => [
                    'username' => empty($request->username) ? ['Please enter username'] : [],
                    'password' => empty($request->password) ? ['Please enter password'] : []
                ]
            ], 401);
        }

        $user = User::where('username', $request->username)->first();
        if (!$user) {
            return response([
                'status' => false,
                'message' => 'Invalid Credentials',
                'errors' => [
                    'username' => ['Username does not exist'],
                    'password' => ['Please enter password']
                ]
            ], 401);
        }

        // Then check if the password is correct
        if (!Hash::check($request->password, $user->password)) {
            return response([
                'status' => false,
                'message' => 'Invalid Credentials',
                'errors' => [
                    'password' => ['Wrong password']
                ]
            ], 401);
        }

        // check if the active or  inactive
        if ($user->active != 1) {
            return response([
                'status' => false,
                'errors' => [
                    'active' => ['Access Denied: Your account is inactive. Please contact the administrator']
                ]
            ], 403);
        }

        // Only allow users with role_id == 1
        if ($user->role_id != 2) {
            return response([
                'status' => false,
                'message' => 'Access Denied: You do not have permission to login.',
                'errors' => [
                    'role_id' => ['Only Rater admin can login.']
                ]
            ], 403);
        }

        // Authenticate the user
        Auth::login($user);

        $user = Auth::user();

        // Check if the user is active
        if (!$user->active) {
            return response([
                'status' => false,
                'message' => 'Your account is inactive. Please contact the administrator.',
            ], 403);
        }

        // Generate a token for the user
        // $token = $user->createToken('my-secret-token')->plainTextToken;

        $user->tokens()->delete();

        $token = $user->createToken('rater_token')->plainTextToken;
        // Set the token in a secure cookie
        // $cookie = cookie('rater_token', $token, 60 * 24, null, null, true, true, false, 'None');

        //  Log the activity using Spatie Activity Log
        // ✅ Fix: Ensure the correct type for Spatie activity log
        if ($user instanceof \App\Models\User) {
            activity('Login')
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties([
                    'rater_name' => $user->name,
                    'rater_username' => $user->username,
                    'role' => $user->role?->role_name,
                    'office' => $user->office,
                    'ip' => $request->ip(),
                    'user_agent' => $request->header('User-Agent'),
                ])
                ->log("Rater {$user->name} logged in successfully.");
        }


        return response([
            'status' => true,
            'message' => 'Login Successfully',
            'user' => [
                'name' => $user->name,
                'position' => $user->position,
                'role_id' => (int)$user->role_id, // Always integer
                'role_name' => $user->role?->name, // Optional chaining in case it's null

            ],
            'token' => $token,
        ]);
    }


    // change password for the rater
    public function updatePassword($validated, $request)
    {

        if ($validated->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validated->errors()
            ], 422);
        }

        // Get authenticated rater
        $rater = $request->user();

        // Verify old password
        if (!Hash::check($request->old_password, $rater->password)) {
            return response()->json([
                'status' => false,
                'errors' => ['old_password' => ['The current password is incorrect']]
            ], 422);
        }

        // Update password
        $rater->password = Hash::make($request->new_password);
        $rater->save();


        // ✅ Log activity using Spatie Activitylog
        if ($rater instanceof \App\Models\User) {
            activity('Change Credentials')
                ->causedBy($rater)
                ->performedOn($rater)
                ->withProperties([
                    'rater_name' => $rater->name,
                    'rater_username' => $rater->username,
                    'role' => $rater->role?->role_name,
                    'office' => $rater->office,
                    'ip' => $request->ip(),
                    'user_agent' => $request->header('User-Agent'),
                ])
                ->log("Rater {$rater->name} changed their password.");
        }


        return response()->json([
            'status' => true,
            'message' => 'Password changed successfully'
        ]);
    }

    // change password rater account if first time login
    public function changePassword($validator, $request)
    {

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect'
            ], 422);
        }

        // Check if new password is same as old
        if (Hash::check($request->new_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'New password must be different from current password'
            ], 422);
        }

        // Update password
        $user->password = Hash::make($request->new_password);
        $user->must_change_password = false;
        $user->password_changed_at = now();
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    }

    //  view rater details assigned jobs
    public function viewDetails($raterId)
    {
        $rater = User::select('id', 'name', 'position', 'office','representative','role_type')
            ->with(['job_batches_rsp' => function ($query) {
                $query->select(
                    'job_batches_rsp.id',
                    'job_batches_rsp.Office',
                    'job_batches_rsp.Position'
                )
                    ->withCount('submissions')
                    ->withPivot('status');
            }])
            ->findOrFail($raterId);

        // ✅ Map job posts to desired output format
        $rater->job_batches_rsp = $rater->job_batches_rsp->map(function ($job) {
            return [
                'id' => $job->id,
                'Office' => $job->Office,
                'Position' => $job->Position,
                'applicant' => (string) $job->submissions_count,
                'status' => $job->pivot->status, // ✅ consistent field
            ];
        });

        return response()->json([
            'id' => $rater->id,
            'name' => $rater->name,
            'position' => $rater->position,
            'office' => $rater->office,
            'job_batches_rsp' => $rater->job_batches_rsp,
            'representative' => $rater->representative,
            'role_type' => $rater->name,

        ]);
    }

    // fetch assigned job post on rater
    public function getAssignedJobs($request)
    {

        $user = Auth::user();

        // $today = Carbon::today();

        $jobBatchIds = DB::table('job_batches_user')
            ->where('user_id', $user->id)

            ->pluck('job_batches_rsp_id');

        $assignedJobs = \App\Models\JobBatchesRsp::select('id', 'Office', 'Position')
            ->whereIn('id', $jobBatchIds)
            // ->whereDate('end_date', '<=', $today) // ✅ only include job posts that are already posted
            ->get()
            ->map(function ($job) use ($user) {
                $job->submitted = rating_score::where('user_id', $user->id)
                    ->where('job_batches_rsp_id', $job->id)
                    ->where('submitted', true)
                    ->exists();
                return $job;
            });

        return response()->json([
            'status' => true,
            'assigned_jobs' => $assignedJobs
        ]);
    }

    // fetch the criteria and applicant of job post
    public function getCriteriaOfJobpostAndApplicant($id) // jobpost id
    {

        $userId = Auth::id(); // ✅ get current logged-in rater

        // Get criteria
        $criteria = criteria_rating::with(['educations', 'experiences', 'trainings', 'performances', 'behaviorals', 'exams', 'jobBatch:id,PositionID'])
            ->where('job_batches_rsp_id', $id)
            ->get();

        // Get applicants with relationships
        $submissions = Submission::where('job_batches_rsp_id', $id)
            ->with([
                'nPersonalInfo.education',
                'nPersonalInfo.work_experience',
                'nPersonalInfo.training',
                'nPersonalInfo.eligibity',
                'nPersonalInfo.rating_score',
                'applicantExamScore'


            ])
            ->where('status', 'qualified')
            ->get();

        $applicants = $submissions->map(function ($submission) use ($userId) {
            $info = $submission->nPersonalInfo;

            $examScore = $submission->applicantExamScore;

            if (!$info && $submission->ControlNo) {
                $xPDS = new \App\Http\Controllers\xPDSController();
                $employeeData = $xPDS->getPersonalDataSheet(new \Illuminate\Http\Request([
                    'controlno' => $submission->ControlNo
                ]));

                $employeeJson = $employeeData->getData(true);

                $info = [
                    'firstname' => $employeeJson['User'][0]['Firstname'] ?? '',
                    'lastname' => $employeeJson['User'][0]['Surname'] ?? '',
                    'education' => $employeeJson['Education'] ?? [],
                    'eligibity' => $employeeJson['Eligibility'] ?? [],
                    'work_experience' => $employeeJson['Experience'] ?? [],
                    'training' => $employeeJson['Training'] ?? [],
                ];

                $ratingScore = \App\Models\rating_score::where('ControlNo', $submission->ControlNo)->first();

                // ✅ Only fetch draft_score for the logged-in rater
                $draftScore  = \App\Models\draft_score::where('ControlNo', $submission->ControlNo)
                    ->where('user_id', $userId)
                    ->where('job_batches_rsp_id', $submission->job_batches_rsp_id) // 🔑 filter by current job post
                    ->first();
            } else {
                $ratingScore = $info->rating_score ?? null;

                // ✅ Filter draft_score by rater
                $draftScore = \App\Models\draft_score::where('nPersonalInfo_id', $submission->nPersonalInfo_id)
                    ->where('user_id', $userId)
                    ->where('job_batches_rsp_id', $submission->job_batches_rsp_id) // 🔑 filter by current job post
                    ->first();
            }

            // 🔄 Standardize datasets (education, eligibility, training, experience)
            $educationData = collect($info['education'] ?? [])->map(function ($edu) {
                return [
                    'level'           => $edu['Education'] ?? $edu['level'] ?? null,
                    'school_name'     => $edu['School'] ?? $edu['school_name'] ?? null,
                    'degree'          => $edu['Degree'] ?? $edu['degree'] ?? null,
                    'attendance_from' => $edu['DateAttend'] ?? $edu['attendance_from'] ?? null,
                    'attendance_to'   => $edu['attendance_to'] ?? null,
                    'year_graduated'  => $edu['year_graduated'] ?? null,
                    'highest_units'   => $edu['NumUnits'] ?? $edu['highest_units'] ?? null,
                    'scholarship'     => $edu['Honors'] ?? $edu['scholarship'] ?? null,
                ];
            });

            $eligibityData = collect($info['eligibity'] ?? [])->map(function ($eli) {
                return [
                    'eligibility'          => $eli['CivilServe'] ?? $eli['eligibility'] ?? null,
                    'rating'               => $eli['Rates'] ?? $eli['rating'] ?? null,
                    'date_of_examination'  => $eli['Dates'] ?? $eli['date_of_examination'] ?? null,
                    'place_of_examination' => $eli['Place'] ?? $eli['place_of_examination'] ?? null,
                    'license_number'       => $eli['LNumber'] ?? $eli['license_number'] ?? null,
                    'date_of_validity'     => $eli['LDate'] ?? $eli['date_of_validity'] ?? null,
                ];
            });

            $trainingData = collect($info['training'] ?? [])->map(function ($train) {
                return [
                    'training_title'      => $train['Training'] ?? $train['training_title'] ?? null,
                    'inclusive_date_from' => $train['DateFrom'] ?? $train['inclusive_date_from'] ?? null,
                    'inclusive_date_to'   => $train['DateTo'] ?? $train['inclusive_date_to'] ?? null,
                    'number_of_hours'     => $train['NumHours'] ?? $train['number_of_hours'] ?? null,
                    'type'                => $train['Type'] ?? $train['type'] ?? null,
                    'conducted_by'        => $train['Conductor'] ?? $train['conducted_by'] ?? null,
                ];
            });

            $experienceData = collect($info['work_experience'] ?? [])->map(function ($exp) {
                return [
                    'work_date_from'       => $exp['WFrom'] ?? $exp['work_date_from'] ?? null,
                    'work_date_to'         => $exp['WTo'] ?? $exp['work_date_to'] ?? null,
                    'position_title'       => $exp['WPosition'] ?? $exp['position_title'] ?? null,
                    'department'           => $exp['WCompany'] ?? $exp['department'] ?? null,
                    'monthly_salary'       => $exp['WSalary'] ?? $exp['monthly_salary'] ?? null,
                    'salary_grade'         => $exp['WGrade'] ?? $exp['salary_grade'] ?? null,
                    'status_of_appointment' => $exp['Status'] ?? $exp['status_of_appointment'] ?? null,
                    'government_service'   => $exp['WGov'] ?? $exp['government_service'] ?? null,
                ];
            });

            return [
                // applicant credentials
                'id'              => $submission->id,
                'nPersonalInfo_id' => $submission->nPersonalInfo_id,
                'ControlNo'       => $submission->ControlNo,
                'exam_score'       => (int) $submission->exam_score,
                'firstname'       => $info['firstname'] ?? '',
                'lastname'        => $info['lastname'] ?? '',

                // applicant score
                'applicant_exam_score' => $examScore ? [
                    'exam_score'      => $examScore->exam_score ?? null,
                    'exam_total_score' => $examScore->exam_total_score ?? null,

                ] : null,

                // applicant rating score
                'rating_score'    => [
                    'education_score'  => $ratingScore->education_score ?? null,
                    'experience_score' => $ratingScore->experience_score ?? null,
                    'training_score'   => $ratingScore->training_score ?? null,
                    'performance_score' => $ratingScore->performance_score ?? null,
                    'behavioral_score' => $ratingScore->behavioral_score ?? null,
                    'exam_score' => $ratingScore->exam_score ?? null, // add
                    'exam_percentage' => $ratingScore->exam_percentage ?? null, //,
                    'behavioral_score' => $ratingScore->behavioral_score ?? null,
                    'total_qs'         => $ratingScore->total_qs ?? null,
                    'grand_total'      => $ratingScore->grand_total ?? null,
                    'ranking'          => $ratingScore->ranking ?? null,
                ],
                // applicant draft score
                'draft_score'     => [
                    'education_score'  => $draftScore->education_score ?? null,
                    'experience_score' => $draftScore->experience_score ?? null,
                    'training_score'   => $draftScore->training_score ?? null,
                    'performance_score' => $draftScore->performance_score ?? null,
                    'behavioral_score' => $draftScore->behavioral_score ?? null,
                    'exam_score' => $draftScore->exam_score ?? null, // add
                    'exam_percentage' => $draftScore->exam_percentage ?? null, //,
                    'total_qs'         => $draftScore->total_qs ?? null,
                    'grand_total'      => $draftScore->grand_total ?? null,
                    'ranking'          => $draftScore->ranking ?? null,
                ],

                // applicant information
                'education'       => $educationData,
                'work_experience' => $experienceData,
                'training'        => $trainingData,
                'eligibity'       => $eligibityData,
            ];
        });

        return response()->json([
            'status'    => true,
            'criteria'  => $criteria,
            'applicants' => $applicants,
        ]);
    }

    // fetch all raters
    public function getAllRaters()
    {
        try {
            $users = User::where('role_id', 2)
                ->with(['job_batches_rsp' => function ($q) {
                    // Fetch only job posts assigned to the rater that are still pending
                    $q->select('job_batches_rsp.id', 'job_batches_rsp.Position')
                        ->wherePivot('status', 'pending');
                }])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($user) {
                    // Count only pending jobs
                    $pendingCount = $user->job_batches_rsp()
                        ->wherePivot('status', 'pending')
                        ->count();

                    // Count complete jobs (for info)
                    $completeCount = $user->job_batches_rsp()
                        ->wherePivot('status', 'complete')
                        ->count();

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'office' => $user->office,
                    'representative' => $user->representative,
                    'role_type' => $user->role_type,
                        'pending' => $pendingCount,
                        'active' => $user->active,
                        'completed' => $completeCount,
                        'created_at' => $user->created_at->format('Y-m-d H:i:s'),
                        'updated_at' => $user->updated_at->format('Y-m-d H:i:s'),
                        // Only pending job titles shown
                        'job_batches_rsp' => $user->job_batches_rsp->map(function ($job) {
                            return [
                                'id' => $job->id,
                                'position' => $job->Position
                            ];
                        }),

                    ];
                });

            return response()->json([
                'status' => true,
                'message' => 'Raters retrieved successfully',
                'data' => $users
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve raters',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // this function will fetch all rater username on the login page
    public function get_rater_usernames()
    {
        try {
            $users = User::where('role_id', 2)->where('active', 1)
                ->orderBy('created_at', 'desc') // Order by latest created first
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'username' => $user->username,
                        'office' => $user->office,
                    ];
                });

            return response()->json([
                'status' => true,
                'message' => 'Raters retrieved successfully',
                'data' => $users
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve raters',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // storing the applicant score
    public function storeScore($request) // storing the score of the applicant
    {
        try {
            $user = Auth::user();
            $userId = $user->id;
            $raterName = $user->name; // get rater name from users table
            $data = $request->all();

            if (!is_array($data)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid data format. Expected an array of submissions.'
                ], 422);
            }

            // ✅ Check if already submitted
            $jobBatchId = $data[0]['job_batches_rsp_id'] ?? null;

            $exists = rating_score::where('user_id', $userId)
                ->where('job_batches_rsp_id', $jobBatchId)
                ->where('submitted', true)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already submitted your scores for this job post.',
                    'close_form' => true
                ], 409);
            }

            $results = [];
            $errors = [];

            DB::beginTransaction();

            foreach ($data as $index => $item) {
                if (!is_array($item)) {
                    $errors[] = [
                        'index' => $index,
                        'errors' => ['Invalid format. Each item must be an object.']
                    ];
                    continue;
                }

                $validator = Validator::make($item, [
                    'nPersonalInfo_id' => 'nullable|exists:nPersonalInfo,id',
                    'ControlNo' => 'nullable|string|max:255',
                    'job_batches_rsp_id' => 'required|exists:job_batches_rsp,id',
                    'education_score' => 'required|numeric|min:0|max:100',
                    'experience_score' => 'required|numeric|min:0|max:100',
                    'training_score' => 'required|numeric|min:0|max:100',
                    'performance_score' => 'required|numeric|min:0|max:100',
                    'behavioral_score' => 'nullable|numeric|min:0|max:100',
                    'exam_score' => 'nullable|numeric|min:0|max:100',
                    'exam_percentage' => 'nullable|numeric|min:0|max:100',
                    'total_qs' => 'required|numeric|min:0|max:100',
                    'grand_total' => 'required|numeric|min:0|max:100',
                    'ranking' => 'required|integer',

                ]);

                if ($validator->fails()) {
                    $errors[] = [
                        'index' => $index,
                        'errors' => $validator->errors()
                    ];
                    continue;
                }

                $validated = $validator->validated();

                // Create record with submitted = true
                $submission = rating_score::create([
                    'user_id' => $userId,
                    'nPersonalInfo_id' => $validated['nPersonalInfo_id'],
                    'ControlNo' => $validated['ControlNo'],
                    'job_batches_rsp_id' => $validated['job_batches_rsp_id'],
                    'education_score' => $validated['education_score'],
                    'experience_score' => $validated['experience_score'],
                    'training_score' => $validated['training_score'],
                    'performance_score' => $validated['performance_score'],
                    'behavioral_score' => $validated['behavioral_score'],
                    'exam_score'      => $validated['exam_score'] ?? null,
                    'exam_percentage' => $validated['exam_percentage'] ?? null,
                    'total_qs' => $validated['total_qs'],
                    'grand_total' => $validated['grand_total'],
                    'ranking' => $validated['ranking'],
                    'evaluated_at' => now(),
                    'submitted' => true,
                    'rater_name' => $raterName, // ✅ automatically assign rater's name

                ]);

                $results[] = $submission;
            }
            // ✅ Auto-update pivot table when a rater has scored
            DB::table('job_batches_user')
                ->where('user_id', $userId)
                // ->where('job_batches_rsp_id', $jobBatchId)
                ->where('job_batches_rsp_id', $validated['job_batches_rsp_id'])
                ->update(['status' => 'complete']);

            if (!empty($errors)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed for some items',
                    'errors' => $errors,
                    'processed_count' => count($results)
                ], 422);
            }

            DB::commit();

            //  Log activity
            // Get job post info
            $jobPost = DB::table('job_batches_rsp')
                ->select('Position', 'Office')
                ->where('id', $jobBatchId)
                ->first();

            // Log activity
            if ($user instanceof \App\Models\User) {
                activity('Score')
                    ->causedBy($user)
                    ->performedOn($user)
                    ->withProperties([
                        'name' => $user->name,
                        'username' => $user->username,
                        'role' => $user->role?->role_name,
                        'office' => $user->office,
                        'ip' => $request->ip(),
                        'user_agent' => $request->header('User-Agent'),
                        'job_position' => $jobPost->Position ?? 'N/A',
                        'job_office' => $jobPost->Office ?? 'N/A',
                        'submitted_count' => count($results),
                    ])
                    ->log("Rater {$user->name} submitted scores for job '{$jobPost->Position}' in office '{$jobPost->Office}'.");
            }

            return response()->json([
                'success' => true,
                'message' => 'Successfully created all records.',
                'data' => $results,
                'count' => count($results),

            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            // Log::error('Error storing rating scores: ' . $e->getMessage(), [
            //     'trace' => $e->getTraceAsString(),
            //     'request_data' => $request->all()
            // ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while storing the ratings. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }


    // draft the score of applicants
    public function draftScore($request)
    {
        try {
            $user = Auth::user();
            $userId = $user->id;
            $raterName = $user->name; // get rater name from users table
            $data = $request->all();


            if (!is_array($data)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid data format. Expected an array of submissions.'
                ], 422);
            }

            $results = [];
            $errors = [];

            foreach ($data as $index => $item) {
                if (!is_array($item)) {
                    $errors[] = [
                        'index' => $index,
                        'errors' => ['Invalid format. Each item must be an object.']
                    ];
                    continue;
                }

                $validator = Validator::make($item, [
                    'nPersonalInfo_id' => 'nullable|exists:nPersonalInfo,id',
                    'ControlNo' => 'nullable|string|max:255',
                    'job_batches_rsp_id' => 'required|exists:job_batches_rsp,id',
                    'education_score' => 'nullable|numeric|min:0|max:100',
                    'experience_score' => 'nullable|numeric|min:0|max:100',
                    'training_score' => 'nullable|numeric|min:0|max:100',
                    'performance_score' => 'nullable|numeric|min:0|max:100',
                    'behavioral_score' => 'nullable|numeric|min:0|max:100',
                    'exam_score' => 'nullable|numeric|min:0|max:100',
                    'exam_percentage' => 'nullable|numeric|min:0|max:100',
                    'total_qs' => 'nullable|numeric|min:0|max:100',
                    'grand_total' => 'nullable|numeric|min:0|max:100',
                    'ranking' => 'nullable|integer',


                ]);

                if ($validator->fails()) {
                    $errors[] = [
                        'index' => $index,
                        'errors' => $validator->errors()
                    ];
                    continue;
                }

                $validated = $validator->validated();

                $submission = draft_score::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'nPersonalInfo_id' => $validated['nPersonalInfo_id'],
                        'ControlNo' => $validated['ControlNo'],
                        'job_batches_rsp_id' => $validated['job_batches_rsp_id'],
                    ],
                    [
                        'education_score' => $validated['education_score'],
                        'experience_score' => $validated['experience_score'],
                        'training_score' => $validated['training_score'],
                        'performance_score' => $validated['performance_score'],
                        'behavioral_score' => $validated['behavioral_score'],
                        'exam_score'      => $validated['exam_score'] ?? null,
                        'exam_percentage' => $validated['exam_percentage'] ?? null,
                        'total_qs' => $validated['total_qs'],
                        'grand_total' => $validated['grand_total'],
                        'ranking' => $validated['ranking'],
                        'evaluated_at' => now(),
                        'rater_name' => $raterName, // ✅ automatically assign rater's name

                    ]
                );

                $results[] = $submission;
            }

            // Log activity after
            // if ($user instanceof \App\Models\User) {
            //     $jobBatchIds = collect($results)->pluck('job_batches_rsp_id')->unique()->join(', ');
            //     activity($user->name)
            //         ->causedBy($user)
            //         ->performedOn($user)
            //         ->withProperties([
            //             'username' => $user->username,
            //             'role' => $user->role?->role_name,
            //             'office' => $user->office,
            //             'ip' => $request->ip(),
            //             'user_agent' => $request->header('User-Agent'),
            //             'job_batches_rsp_ids' => $jobBatchIds,
            //             'saved_count' => count($results),
            //         ])
            //         ->log("Rater {$user->name} saved draft scores for job post batch ID: {$jobBatchIds}.");
            // }
            // Log activity after saving drafts
            if ($user instanceof \App\Models\User && !empty($results)) {
                $jobBatchIds = collect($results)->pluck('job_batches_rsp_id')->unique();

                // Fetch job post details for all involved batches
                $jobPosts = DB::table('job_batches_rsp')
                    ->whereIn('id', $jobBatchIds)
                    ->select('id', 'Position', 'Office')
                    ->get()
                    ->keyBy('id'); // key by id for easy lookup

                $jobDetails = $jobBatchIds->map(function ($id) use ($jobPosts) {
                    $job = $jobPosts[$id] ?? null;
                    if ($job) {
                        return "{$job->Position} ({$job->Office})";
                    }
                    return "ID {$id}";
                })->join(', ');

                activity('DraftScore')
                    ->causedBy($user)
                    ->performedOn($user)
                    ->withProperties([
                        'name' => $user->name,
                        'username' => $user->username,
                        'role' => $user->role?->role_name,
                        'office' => $user->office,
                        'ip' => $request->ip(),
                        'user_agent' => $request->header('User-Agent'),
                        'job_details' => $jobDetails,
                        'saved_count' => count($results),
                    ])
                    ->log("Rater {$user->name} saved draft scores for job(s): {$jobDetails}.");
            }

            return response()->json([
                'success' => true,
                'message' => 'Draft saved successfully.',
                'data' => $results,
                'errors' => $errors,
            ], 200);
        } catch (Exception $e) {
            // Log::error('Error storing draft scores: ' . $e->getMessage(), [
            //     'trace' => $e->getTraceAsString(),
            //     'request_data' => $request->all()
            // ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while saving the draft.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }


    // get the score of the applicant on the rating_score
    public function raterWithAssignedJob()
    {
        try {
            $users = User::where('role_id', 2)
                ->with(['job_batches_rsp' => function ($q) {
                    // Fetch only job posts assigned to the rater that are still pending
                    $q->select('job_batches_rsp.id', 'job_batches_rsp.Position', 'job_batches_rsp.post_date', 'job_batches_rsp.end_date')
                        ->wherePivot('status', 'complete');
                }])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($user) {


                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'office' => $user->office,
                        'active' => $user->active,
                        'created_at' => $user->created_at->format('Y-m-d H:i:s'),
                        'updated_at' => $user->updated_at->format('Y-m-d H:i:s'),
                        // Only pending job titles shown
                        'job_batches_rsp' => $user->job_batches_rsp->map(function ($job) {
                            return [
                                'id' => $job->id,
                                'position' => $job->Position,
                                'post_date' => $job->post_date ? Carbon::parse($job->post_date)->format('m/d/Y') : null,
                                'end_date' => $job->end_date ? Carbon::parse($job->end_date)->format('m/d/Y') : null,
                            ];
                        }),

                    ];
                });

            return response()->json([
                'status' => true,
                'message' => 'Raters retrieved successfully',
                'data' => $users
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve raters',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // fetch the score of applicant rate by rater
    public function getScoreOfApplicantRateByRater($userId, $jobPostid) // jobpost id
    {

        // Get applicants with relationships
        $submissions = Submission::where('job_batches_rsp_id', $jobPostid)
            ->with([
                'nPersonalInfo.education',
                'nPersonalInfo.work_experience',
                'nPersonalInfo.training',
                'nPersonalInfo.eligibity',
                'nPersonalInfo.rating_score',
                'applicantExamScore'

            ])
            ->where('status', 'qualified')
            ->get();

        $applicants = $submissions->map(function ($submission) use ($userId) {
            $info = $submission->nPersonalInfo;

            if (!$info && $submission->ControlNo) {
                $xPDS = new \App\Http\Controllers\xPDSController();
                $employeeData = $xPDS->getPersonalDataSheet(new \Illuminate\Http\Request([
                    'controlno' => $submission->ControlNo
                ]));

                $employeeJson = $employeeData->getData(true);

                $info = [
                    'firstname' => $employeeJson['User'][0]['Firstname'] ?? '',
                    'lastname' => $employeeJson['User'][0]['Surname'] ?? '',
                    'education' => $employeeJson['Education'] ?? [],
                    'eligibity' => $employeeJson['Eligibility'] ?? [],
                    'work_experience' => $employeeJson['Experience'] ?? [],
                    'training' => $employeeJson['Training'] ?? [],
                ];

                $ratingScore = \App\Models\rating_score::where('ControlNo', $submission->ControlNo)->first();

                // ✅ Only fetch draft_score for the logged-in rater
                $draftScore  = \App\Models\draft_score::where('ControlNo', $submission->ControlNo)
                    ->where('user_id', $userId)
                    ->where('job_batches_rsp_id', $submission->job_batches_rsp_id) // 🔑 filter by current job post
                    ->first();
            } else {
                $ratingScore = $info->rating_score ?? null;

                // ✅ Filter draft_score by rater
                $draftScore = \App\Models\draft_score::where('nPersonalInfo_id', $submission->nPersonalInfo_id)
                    ->where('user_id', $userId)
                    ->where('job_batches_rsp_id', $submission->job_batches_rsp_id) // 🔑 filter by current job post
                    ->first();
            }

            return [
                // applicant credentials
                'id'              => $submission->id,
                'nPersonalInfo_id' => $submission->nPersonalInfo_id,
                'ControlNo'       => $submission->ControlNo,
                'exam_score'       => (int) $submission->exam_score,
                'firstname'       => $info['firstname'] ?? '',
                'lastname'        => $info['lastname'] ?? '',


                // applicant rating score
                'rating_score'    => [
                    'education_score'  => $ratingScore->education_score ?? null,
                    'experience_score' => $ratingScore->experience_score ?? null,
                    'training_score'   => $ratingScore->training_score ?? null,
                    'performance_score' => $ratingScore->performance_score ?? null,
                    'behavioral_score' => $ratingScore->behavioral_score ?? null,
                    'exam_score' => $ratingScore->exam_score ?? null, // add
                    'exam_percentage' => $ratingScore->exam_percentage ?? null, //,
                    'behavioral_score' => $ratingScore->behavioral_score ?? null,
                    'total_qs'         => $ratingScore->total_qs ?? null,
                    'grand_total'      => $ratingScore->grand_total ?? null,
                    'ranking'          => $ratingScore->ranking ?? null,
                ],
                // applicant draft score
                // 'draft_score'     => [
                //     'education_score'  => $draftScore->education_score ?? null,
                //     'experience_score' => $draftScore->experience_score ?? null,
                //     'training_score'   => $draftScore->training_score ?? null,
                //     'performance_score' => $draftScore->performance_score ?? null,
                //     'behavioral_score' => $draftScore->behavioral_score ?? null,
                //     'exam_score' => $draftScore->exam_score ?? null, // add
                //     'exam_percentage' => $draftScore->exam_percentage ?? null, //,
                //     'total_qs'         => $draftScore->total_qs ?? null,
                //     'grand_total'      => $draftScore->grand_total ?? null,
                //     'ranking'          => $draftScore->ranking ?? null,
                // ],

            ];
        });

        return response()->json([
            'status'    => true,
            'message' => 'Fetch successfully',
            'applicants' => $applicants,
        ]);
    }

    // list of applicant applied internal - external
    public function getApplicantBaseOnRaterAssigned($request)
    {
        $user = Auth::user();

        $jobBatchIds = DB::table('job_batches_user')
            ->where('user_id', $user->id)
            ->pluck('job_batches_rsp_id');

        $search  = $request->input('search');
        $perPage = $request->input('per_page', 10);

        // ── External applicants — linked to nPersonalInfo ────────────────────────
        $external = Submission::query()
            ->from('submission')
            ->join('nPersonalInfo as p', 'submission.nPersonalInfo_id', '=', 'p.id')
            ->whereNotNull('submission.nPersonalInfo_id')
            ->whereIn('submission.job_batches_rsp_id', $jobBatchIds)
            ->select(
                DB::raw('MIN(p.id) as nPersonal_id'),
                DB::raw('NULL as ControlNo'),
                'p.firstname',
                'p.lastname',
                DB::raw('CONVERT(varchar, p.date_of_birth, 23) as date_of_birth'),
                DB::raw('COUNT(submission.id) as applied_job'),
                DB::raw("'external' as applicant_type")
            )
            ->groupBy('p.firstname', 'p.lastname', 'p.date_of_birth');

        if ($search) {
            $external->where(function ($q) use ($search) {
                $q->where('p.firstname', 'like', "%{$search}%")
                    ->orWhere('p.lastname', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(p.firstname, ' ', p.lastname) LIKE ?", ["%{$search}%"]);
            });
        }

        // ── Internal applicants — joined to xPersonal via ControlNo ─────────────
        $internal = Submission::query()
            ->from('submission')
            ->join('xPersonal as xp', 'submission.ControlNo', '=', 'xp.ControlNo')
            ->whereNull('submission.nPersonalInfo_id')
            ->whereIn('submission.job_batches_rsp_id', $jobBatchIds)
            ->select(
                DB::raw('NULL as nPersonal_id'),
                'submission.ControlNo',
                DB::raw('xp.Firstname as firstname'),
                DB::raw('xp.Surname as lastname'),
                DB::raw('CONVERT(varchar, xp.BirthDate, 23) as date_of_birth'),
                DB::raw('COUNT(submission.id) as applied_job'),
                DB::raw("'internal' as applicant_type")
            )
            ->groupBy('xp.Firstname', 'xp.Surname', 'xp.BirthDate', 'submission.ControlNo');

        if ($search) {
            $internal->where(function ($q) use ($search) {
                $q->where('xp.Firstname', 'like', "%{$search}%")
                    ->orWhere('xp.Surname', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(xp.Firstname, ' ', xp.Surname) LIKE ?", ["%{$search}%"])
                    ->orWhere('submission.ControlNo', 'like', "%{$search}%");
            });
        }

        // ── UNION ALL + paginate ─────────────────────────────────────────────────
        $union = $external->unionAll($internal);

        $results = DB::table(DB::raw("({$union->toSql()}) as combined"))
            ->mergeBindings($union->getQuery())
            ->paginate($perPage);

        return response()->json([
            'status' => true,
            'data'   => $results,
        ]);
    }

    // fetch the applicant details he applied
    public function getApplicantDetails($request)
    {

        $validated = $request->validate([
            'firstname'     => 'required|string',
            'lastname'      => 'required|string',
            'date_of_birth' => 'required|date',
        ]);


        $user = Auth::user();


        $jobBatchIds = DB::table('job_batches_user')
            ->where('user_id', $user->id)
            ->pluck('job_batches_rsp_id');


        $firstname     = trim(strtolower($validated['firstname']));
        $lastname      = trim(strtolower($validated['lastname']));
        $date_of_birth = \Carbon\Carbon::parse($validated['date_of_birth'])->toDateString();

        // ── External applicants (nPersonalInfo_id is NOT NULL) ──────────────────
        $external = Submission::select('id', 'nPersonalInfo_id', 'ControlNo', 'job_batches_rsp_id', 'status')
        ->whereIn('status', ['Qualified','Hired'])
        ->whereIn('job_batches_rsp_id', $jobBatchIds)
            ->whereNotNull('nPersonalInfo_id')
            ->whereHas('nPersonalInfo', function ($query) use ($firstname, $lastname, $date_of_birth) {
                $query->whereDate('date_of_birth', $date_of_birth)
                    ->where(function ($q) use ($firstname, $lastname) {
                        $q->where(function ($q2) use ($firstname, $lastname) {
                            $q2->whereRaw('LOWER(TRIM(firstname)) = ?', [$firstname])
                                ->whereRaw('LOWER(TRIM(lastname)) = ?', [$lastname]);
                        })->orWhere(function ($q2) use ($firstname, $lastname) {
                            $q2->whereRaw('LOWER(TRIM(firstname)) = ?', [$lastname])
                                ->whereRaw('LOWER(TRIM(lastname)) = ?', [$firstname]);
                        });
                    });
            })
            ->with([
                'nPersonalInfo:id,firstname,lastname,date_of_birth',
            'job_batch_rsp:id,Position,Office,SalaryGrade,salaryMin,salaryMax,status',
            ])
            ->get()
            ->map(function ($item) {
                $item->applicant_type = 'external';
                $item->personal_info  = $item->nPersonalInfo;
                return $item;
            });

        // ── Internal applicants (nPersonalInfo_id IS NULL, name from xPersonal) ─
        $internal = Submission::select('id', 'nPersonalInfo_id', 'ControlNo', 'job_batches_rsp_id', 'status')
        ->whereIn('status', ['Qualified','Hired'])
        ->whereIn('job_batches_rsp_id', $jobBatchIds)
            ->whereNull('nPersonalInfo_id')
            ->whereHas('xPersonal', function ($query) use ($firstname, $lastname, $date_of_birth) {
                $query->whereDate('BirthDate', $date_of_birth)
                    ->where(function ($q) use ($firstname, $lastname) {
                        $q->where(function ($q2) use ($firstname, $lastname) {
                            $q2->whereRaw('LOWER(TRIM(Firstname)) = ?', [$firstname])
                                ->whereRaw('LOWER(TRIM(Surname)) = ?', [$lastname]);
                        })->orWhere(function ($q2) use ($firstname, $lastname) {
                            $q2->whereRaw('LOWER(TRIM(Firstname)) = ?', [$lastname])
                                ->whereRaw('LOWER(TRIM(Surname)) = ?', [$firstname]);
                        });
                    });
            })
            ->with([
                'xPersonal',
            'job_batch_rsp:id,Position,Office,SalaryGrade,salaryMin,salaryMax,status',
            ])
            ->get()
            ->map(function ($item) {
                $item->applicant_type = 'internal';
                $item->personal_info  = $item->xPersonal
                    ? [
                        'firstname'     => $item->xPersonal->Firstname,
                        'lastname'      => $item->xPersonal->Surname,
                        'date_of_birth' => \Carbon\Carbon::parse($item->xPersonal->BirthDate)->format('m/d/Y'),
                    ]
                    : null;
                $item->n_personal_info = $item->xPersonal  // ← mirrors external's n_personal_info
                    ? [
                        'firstname'     => $item->xPersonal->Firstname,
                        'lastname'      => $item->xPersonal->Surname,
                        'date_of_birth' => \Carbon\Carbon::parse($item->xPersonal->BirthDate)->format('m/d/Y'),
                    ]
                    : null;
                unset($item->xPersonal);
                unset($item->x_personal);
                return $item;
            });

        // ── Merge both result sets ───────────────────────────────────────────────
        $applicants = $external->merge($internal);

        if ($applicants->isEmpty()) {
            return collect(); // return empty, let controller handle it
        }

        return $applicants; // just return the collection

    }

    // joblist assigned to rater
    public function jobPostList($raterId)
    {
        User::findOrFail($raterId);

        // Get job IDs assigned to this rater that are already complete
        $completedJobIds = DB::table('job_batches_user')
            ->where('user_id', $raterId)
            ->where('status', 'complete')
            ->pluck('job_batches_rsp_id');

        // Fetch all job posts EXCEPT the completed ones
        $jobList = JobBatchesRsp::whereNotIn('id', $completedJobIds)
            ->select('id', 'Position', 'Office', 'status')
            ->get();

        return response()->json([
            'status'  => true,
            'message' => 'Job posts retrieved successfully',
            'data'    => $jobList,
        ]);
    }
}
