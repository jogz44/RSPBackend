<?php

namespace App\Services;

use App\Models\JobBatchesRsp;
use App\Models\OnCriteriaJob;
use App\Models\OnFundedPlantilla;
use App\Models\Submission;
use App\Models\vwActive;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class JobPostService
{
    /**
     * Create a new class instance.
     */
    protected $activityLogService;


    public function __construct(ActivityLogService $activityLogService)
    {
        //
        $this->activityLogService = $activityLogService;
    }

    // updating the job post status unoccupied
    public function unoccupied($validated, $JobPostingId)
    {


        $jobPost = JobBatchesRsp::findOrFail($JobPostingId);
        $jobPost->update([
            'status' => $validated['status'],
        ]);

        $user = Auth::user();

        if ($user instanceof \App\Models\User) {
            activity('Job Post Status Update')
                ->causedBy($user)
                ->performedOn($jobPost)
                ->withProperties([
                    'name'   => $user->name,
                    'username'     => $user->username,
                    'job_post_id'  => $jobPost->id,
                    'position'     => $jobPost->Position ?? null,
                    'item_no'      => $jobPost->ItemNo ?? null,
                    'old_status'   => $jobPost->getOriginal('status'),
                    'new_status'   => $validated['status'],
                    'ip'           => request()->ip(),
                    'user_agent'   => request()->header('User-Agent'),
                ])
                ->log("{$user->name} marked job post {$jobPost->Position} as Unoccupied.");
        }

        // $this->activityLogService->logCreateJobPost($user,$jobPost);


        return response()->json([
            'message' => 'Status updated successfully.',
            'data' => $jobPost,
        ]);
    }

    //  this function fetching the only didnt meet the end_date
    public function jobpostListAvailable()
    {
        // Only fetch jobs where end_post is today or later (still active)
        $today = Carbon::today();
        $activeJobs = JobBatchesRsp::whereDate('post_date', '<=', $today)
        ->whereDate('end_date', '>=', $today)
        // ->orderBy('post_date', 'asc')
        ->whereNotIn('status', ['Unoccupied', 'Occupied', 'Republished'])
             ->orderBy('Position', 'asc')
            ->get();

        return response()->json($activeJobs);
    }

    //fetch job post list with status
    public function jobPostList()
    {
        // 🔹 Fetch job posts EXCLUDING republished ones
        $jobPosts = JobBatchesRsp::select('id', 'Position', 'post_date', 'Office', 'PositionID', 'ItemNo', 'status', 'end_date', 'tblStructureDetails_ID')
           ->orderBy('Position', 'asc')
            ->whereRaw('LOWER(status) != ?', ['republished']) // ✅ exclude republished
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
            ->get();

        foreach ($jobPosts as $job) {
            $originalStatus = strtolower($job->status);
            $newStatus = $originalStatus;

            // Skip manual statuses (do not override)
            $manualStatuses = ['unoccupied', 'occupied', 'closed', 'republished'];
            if (in_array($originalStatus, $manualStatuses)) {
                continue;
            }

            // ✅ Check if all raters have completed their rating
            $allRatersComplete = \App\Models\Job_batches_user::where('job_batches_rsp_id', $job->id)
                ->exists() &&
                !\App\Models\Job_batches_user::where('job_batches_rsp_id', $job->id)
                    ->where('status', '!=', 'complete')
                    ->exists();

            if ($allRatersComplete) {
                $newStatus = 'rated';
            } elseif ($job->hired_count >= 1) {
                $newStatus = 'occupied';
            } elseif ($job->qualified_count > 0 || $job->unqualified_count > 0) {
                $newStatus = $job->pending_count > 0 ? 'pending' : 'assessed';
            } else {
                $newStatus = 'not started';
            }

            // ✅ Update only if changed
            if ($originalStatus !== $newStatus) {
                $job->status = $newStatus;
                $job->save();
            }
        }

        // 🔄 Reload updated list (still excluding republished)
        $jobPosts = JobBatchesRsp::select('id', 'Position', 'post_date', 'Office', 'PositionID', 'ItemNo', 'status', 'end_date', 'tblStructureDetails_ID')
           ->orderBy('Position', 'asc')    
        ->whereRaw('LOWER(status) != ?', ['republished']) // ✅ exclude republished again
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
            ->get();

        return response()->json($jobPosts);
    }


    // filter job post
    public function filter($postDate = null, $endDate = null, $request)
    {
        $allDate = $request->query('allDate', false);


        $query = JobBatchesRsp::select('id', 'Position', 'post_date', 'Office', 'PositionID', 'ItemNo', 'status', 'end_date', 'tblStructureDetails_ID')
            ->whereRaw('LOWER(status) != ?', ['republished'])
            ->withCount([
                'submissions as total_applicants',
                'submissions as qualified_count' => fn($q) => $q->whereRaw('LOWER(status) = ?', ['qualified']),
                'submissions as unqualified_count' => fn($q) => $q->whereRaw('LOWER(status) = ?', ['unqualified']),
                'submissions as pending_count' => fn($q) => $q->whereRaw('LOWER(status) = ?', ['pending']),
                'submissions as hired_count' => fn($q) => $q->whereRaw('LOWER(status) = ?', ['hired']),
            ]);

        if (!$allDate) {
            $query->whereDate('post_date', '>=', $postDate)
                ->whereDate('end_date', '<=', $endDate);
        }

        $jobPosts = $query->get();
        // return response()->json($jobPosts);

        foreach ($jobPosts as $job) {
            $originalStatus = strtolower($job->status);
            $newStatus = $originalStatus;

            // Skip manual statuses (do not override)
            $manualStatuses = ['unoccupied', 'occupied', 'closed', 'republished'];
            if (in_array($originalStatus, $manualStatuses)) {
                continue;
            }

            // ✅ Check if all raters have completed their rating
            $allRatersComplete = \App\Models\Job_batches_user::where('job_batches_rsp_id', $job->id)
                ->exists() &&
                !\App\Models\Job_batches_user::where('job_batches_rsp_id', $job->id)
                    ->where('status', '!=', 'complete')
                    ->exists();

            if ($allRatersComplete) {
                $newStatus = 'rated';
            } elseif ($job->hired_count >= 1) {
                $newStatus = 'occupied';
            } elseif ($job->qualified_count > 0 || $job->unqualified_count > 0) {
                $newStatus = $job->pending_count > 0 ? 'pending' : 'assessed';
            } else {
                $newStatus = 'not started';
            }

            // ✅ Update only if changed
            if ($originalStatus !== $newStatus) {
                $job->status = $newStatus;
                $job->save();
            }
        }

        // 🔄 Reload updated list (still excluding republished)
        $jobPosts = JobBatchesRsp::select('id', 'Position', 'post_date', 'Office', 'PositionID', 'ItemNo', 'status', 'end_date', 'tblStructureDetails_ID')
            ->whereRaw('LOWER(status) != ?', ['republished']) // ✅ exclude republished again
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
            ]);
        // ->get();
        if (!$allDate && $postDate && $endDate) {
            $jobPosts->whereDate('post_date', '>=', $postDate)
                ->whereDate('end_date', '<=', $endDate);
        }

        $jobPosts = $jobPosts->get();

        return response()->json($jobPosts);
    }

    // fetch the job post
    // with or without criteria
    public function fetchJobPostWithCriteria()

    {
        // Get all job posts with criteria and assigned raters
        $jobs = JobBatchesRsp::with(['criteriaRatings', 'users:id,name'])->select('id', 'office', 'isOpen', 'Position', 'PositionID', 'ItemNo')
            ->where('status', '!=', 'occupied') //
            ->get();

        // Add 'status' and 'assigned_raters' to each job
        $jobsWithDetails = $jobs->map(function ($job) {
            $job->status = $job->criteriaRatings->isNotEmpty() ? 'created' : 'no criteria';
            $job->assigned_raters = $job->users; // Include users as assigned raters
            unset($job->users); // Optionally remove the original 'users' relation if not needed directly
            return $job;
        });

        return response()->json($jobsWithDetails);
    }

    // delete job post
    public function delete($id)
    {
        $jobBatch = JobBatchesRsp::findOrFail($id);

        $jobData = [
            'id'        => $jobBatch->id,
            'position'  => $jobBatch->Position ?? null,
            'item_no'   => $jobBatch->ItemNo ?? null,
            'office'    => $jobBatch->Office ?? null,
            'page_no'   => $jobBatch->PageNo ?? null,
            'status'    => $jobBatch->status ?? null,
        ];

        $jobBatch->delete();


        $user = Auth::user();

        $this->activityLogService->logDeleteJobPost($user,$jobBatch);

        return response()->json([
            'message' => 'deleted successfully',
            'jobBatch' => $jobBatch,
        ]);
    }


    // jobpost id args with search and pagination
    public function applicant($id, $request)
    {
        $search  = $request->input('search');
        $perPageInput = $request->input('per_page', 10);

        // Handle "all" — return everything in one page
        $perPage = ($perPageInput === 'all') ? PHP_INT_MAX : (int) $perPageInput;

        // Eager load nPersonalInfo to avoid N+1 queries
        $qualifiedApplicants = Submission::where('job_batches_rsp_id', $id)
            ->with('nPersonalInfo')
            ->get();

        $totalApplicants  = $qualifiedApplicants->count();
        $qualifiedCount   = $qualifiedApplicants->where('status', 'Qualified')->count();
        $unqualifiedCount = $qualifiedApplicants->where('status', 'Unqualified')->count();
        $assessed         = $qualifiedCount + $unqualifiedCount;

        $internalCount = $qualifiedApplicants->filter(fn($s) => !empty($s->ControlNo))->count();
        $externalCount = $qualifiedApplicants->filter(fn($s) => !empty($s->nPersonalInfo_id))->count();

        // ── External applicants ────────────────────────────────────────────────
        $external = Submission::query()
            ->where('submission.job_batches_rsp_id', $id)
            ->join('nPersonalInfo as p', 'submission.nPersonalInfo_id', '=', 'p.id')
            ->select(
                'submission.id as submission_id',
                'submission.nPersonalInfo_id',
                DB::raw('NULL as ControlNo'),
                'submission.job_batches_rsp_id',
                'submission.status',
                'p.firstname',
                'p.lastname',
                DB::raw("CAST(COALESCE(p.created_at, submission.created_at) AS DATE) as application_date"),
                DB::raw("'external' as applicant_type")
            );

        if ($search) {
            $external->where(function ($q) use ($search) {
                $q->where('p.firstname', 'like', "%{$search}%")
                    ->orWhere('p.lastname', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(p.firstname,' ',p.lastname) LIKE ?", ["%{$search}%"]);
            });
        }

        // ── Internal applicants ────────────────────────────────────────────────
        $internal = Submission::query()
            ->where('submission.job_batches_rsp_id', $id)
            ->whereNull('submission.nPersonalInfo_id')
            ->join('xPersonal as xp', 'submission.ControlNo', '=', 'xp.ControlNo')
            ->select(
                'submission.id as submission_id',
                DB::raw('NULL as nPersonalInfo_id'),
                'submission.ControlNo',
                'submission.job_batches_rsp_id',
                'submission.status',
                'xp.Firstname as firstname',
                'xp.Surname as lastname',
                DB::raw("CAST(submission.created_at AS DATE) as application_date"),
                DB::raw("'internal' as applicant_type")
            );

        if ($search) {
            $internal->where(function ($q) use ($search) {
                $q->where('xp.Firstname', 'like', "%{$search}%")
                    ->orWhere('xp.Surname', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(xp.Firstname,' ',xp.Surname) LIKE ?", ["%{$search}%"]);
            });
        }

        // ── UNION ALL + paginate ───────────────────────────────────────────────
        $union = $external->unionAll($internal);

        $applicants = DB::table(DB::raw("({$union->toSql()}) as combined"))
            ->mergeBindings($union->getQuery())
            ->paginate($perPage);

        return response()->json([
            'status'                 => true,
            'qualified_applicants'   => $qualifiedCount,
            'unqualified_applicants' => $unqualifiedCount,
            'assessed'               => "{$assessed}/{$totalApplicants}",
            'total_applicants'       => $totalApplicants,
            'internal_applicants'    => $internalCount,
            'external_applicants'    => $externalCount,
            'applicants'             => $applicants,
        ]);
    }

    // store job post with criteria and pdf file
    public function store($jobValidated, $request)
    {

        // Validate criteria if present
        $criteriaValidated = $request->only(['Education', 'Eligibility', 'Training', 'Experience']);

        // Validate file if present
        if ($request->hasFile('fileUpload')) {
            $fileValidated = $request->validate([
                'fileUpload' => 'required|mimes:pdf|max:5120',
            ]);
        }

        // --- Step 1: Update PageNo if PageNo exists ---
        if ($request->has('PageNo') && $jobValidated['tblStructureDetails_ID'] && $jobValidated['ItemNo']) {
            $exists = DB::table('tblStructureDetails')
                ->where('PageNo', $jobValidated['PageNo'])
                ->where('ItemNo', $jobValidated['ItemNo'])
                ->where('ID', '<>', $jobValidated['tblStructureDetails_ID'])
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Duplicate PageNo and ItemNo already exists in plantilla.'
                ], 422);
            }

            DB::table('tblStructureDetails')
                // ->where('PositionID', $jobValidated['PositionID'])
                ->where('ID', $jobValidated['tblStructureDetails_ID'])
                // ->where('ItemNo', $jobValidated['ItemNo'])
                ->update(['PageNo' => $jobValidated['PageNo']]);
        }

        // --- Step2: Create Job Post if new ---
        $jobBatch = JobBatchesRsp::create($jobValidated);

        // Create criteria if exists
        if (!empty($criteriaValidated)) {
            $criteria = OnCriteriaJob::create([
                'job_batches_rsp_id' => $jobBatch->id,
                'PositionID' => $jobValidated['PositionID'] ?? null,
                'ItemNo' => $jobValidated['ItemNo'] ?? null,
                'Education' => $criteriaValidated['Education'] ?? null,
                'Eligibility' => $criteriaValidated['Eligibility'] ?? null,
                'Training' => $criteriaValidated['Training'] ?? null,
                'Experience' => $criteriaValidated['Experience'] ?? null,
            ]);
        }

        // Handle plantilla and file upload
        $plantilla = new OnFundedPlantilla();
        $plantilla->job_batches_rsp_id = $jobBatch->id;
        $plantilla->PositionID = $jobValidated['PositionID'] ?? null;
        $plantilla->ItemNo = $jobValidated['ItemNo'] ?? null;

        if ($request->hasFile('fileUpload')) {
            $file = $request->file('fileUpload');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $filePath = $file->storeAs('plantilla_files', $fileName, 'public');
            $plantilla->fileUpload = $filePath;
        }

        $plantilla->save();

        // Log activity for creating a job post
        $user = Auth::user();

        $this->activityLogService->logCreateJobPost($user,$jobBatch);


        return response()->json([
            'status' => 'success',
            'message' => 'Job post processed successfully',
            'job_post' => $jobBatch,
            'criteria' => $criteria ?? null,
            'plantilla' => $plantilla
        ], 201);
    }

    public function update($jobValidated, $jobBatchId, $request)
    {


        // 2️⃣ Validate criteria if present
        $criteriaValidated = $request->only(['Education', 'Eligibility', 'Training', 'Experience']);

        // 3️⃣ Validate file if present
        if ($request->hasFile('fileUpload')) {
            $fileValidated = $request->validate([
                'fileUpload' => 'required|mimes:pdf|max:5120',
            ]);
        }

        // 4️⃣ Check for duplicate PageNo + ItemNo
        if ($request->has('PageNo') && $jobValidated['tblStructureDetails_ID'] && $jobValidated['ItemNo']) {
            $exists = DB::table('tblStructureDetails')
                ->where('PageNo', $jobValidated['PageNo'])
                ->where('ItemNo', $jobValidated['ItemNo'])
                ->where('ID', '<>', $jobValidated['tblStructureDetails_ID'])
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Duplicate PageNo and ItemNo already exists in plantilla Please try again.'
                ], 422);
            }

            // Update PageNo in tblStructureDetails
            DB::table('tblStructureDetails')
                ->where('ID', $jobValidated['tblStructureDetails_ID'])
                // ->where('ItemNo', $jobValidated['ItemNo'])
                ->update(['PageNo' => $jobValidated['PageNo']]);
        }

        // 5️⃣ Update Job Batch
        $jobBatch = JobBatchesRsp::findOrFail($jobBatchId);
        $jobBatch->update($jobValidated);

        // 6️⃣ Update criteria if exists
        if (!empty($criteriaValidated)) {
            $criteria = OnCriteriaJob::updateOrCreate(
                ['job_batches_rsp_id' => $jobBatch->id],
                [
                    'PositionID' => $jobValidated['PositionID'] ?? null,
                    'ItemNo' => $jobValidated['ItemNo'] ?? null,
                    'Education' => $criteriaValidated['Education'] ?? null,
                    'Eligibility' => $criteriaValidated['Eligibility'] ?? null,
                    'Training' => $criteriaValidated['Training'] ?? null,
                    'Experience' => $criteriaValidated['Experience'] ?? null,
                ]
            );
        }

       

        // 7️⃣ Update plantilla and file if exists
        $plantilla = OnFundedPlantilla::firstOrNew(['job_batches_rsp_id' => $jobBatch->id]);
        $plantilla->PositionID = $jobValidated['PositionID'] ?? null;
        $plantilla->ItemNo = $jobValidated['ItemNo'] ?? null;

         
        $fileName = null; // initialize before the if block
        if ($request->hasFile('fileUpload')) {
            $file = $request->file('fileUpload');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $filePath = $file->storeAs('plantilla_files', $fileName, 'public');
            $plantilla->fileUpload = $filePath;
        }

        $plantilla->save();

        $user = Auth::user();
      
        $this->activityLogService->logEditJobPost($user,$jobBatch,$jobValidated,$request,$fileName);

        return response()->json([
            'status' => 'success',
            'message' => 'Job post updated successfully',
            'job_post' => $jobBatch,
            'criteria' => $criteria ?? null,
            'plantilla' => $plantilla
        ], 200);
    }


    public function republished($validated, $request) // republished the job post
    {

        // ✅ Step 3: Validate file (required even if not in JobBatchesRsp)
        $fileValidated = $request->validate([
            'fileUpload' => 'required|mimes:pdf|max:5120',
        ]);

        // ✅ Step 4: Mark old job as Republished
        JobBatchesRsp::where('id', $validated['old_job_id'])
            ->update(['status' => 'Republished']);

        // ✅ Step 5: Create new Job Post
        $jobBatch = JobBatchesRsp::create($validated);

        // ✅ Step 6: Create new Criteria
        $criteria = OnCriteriaJob::create([
            'job_batches_rsp_id' => $jobBatch->id,
            'PositionID' => $validated['PositionID'] ?? null,
            'ItemNo' => $validated['ItemNo'] ?? null,
            'Education' => $validated['Education'] ?? null,
            'Eligibility' => $validated['Eligibility'] ?? null,
            'Training' => $validated['Training'] ?? null,
            'Experience' => $validated['Experience'] ?? null,
        ]);



        // ✅ Step 7: Handle plantilla and file upload
        $file = $request->file('fileUpload');
        $fileName = time() . '_' . $file->getClientOriginalName();
        $filePath = $file->storeAs('plantilla_files', $fileName, 'public');



        $plantilla = OnFundedPlantilla::create([
            'job_batches_rsp_id' => $jobBatch->id,
            'PositionID' => $validated['PositionID'] ?? null,
            'ItemNo' => $validated['ItemNo'] ?? null,
            'fileUpload' => $filePath,
        ]);

        $user = Auth::user();

        $this->activityLogService->logRepublishedJobPost($user,$jobBatch,$validated,$fileName);

        // ✅ Step 8: Return response
        return response()->json([
            'status' => 'success',
            'message' => 'Job post republished successfully',
            'job_post' => $jobBatch,
            'criteria' => $criteria,
            'plantilla' => $plantilla
        ], 201);
    }
}
