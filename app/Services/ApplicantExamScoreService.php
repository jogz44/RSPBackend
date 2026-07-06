<?php

namespace App\Services;

use App\Models\ApplicantExamScore;
use App\Models\criteria\criteria_rating;
use App\Models\Submission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApplicantExamScoreService
{
    /**
     * Create a new class instance.
     */

    protected $activityLogService;

    public function __construct(ActivityLogService $activityLogService)
    {
        //

        $this->activityLogService  = $activityLogService;
    }

    // store the applicant exam score
    public function addExamScoreOfApplicant($validated)
    {
        $results = [];
        $user = Auth::user();

        foreach ($validated['applicants'] as $applicant) {

            // 1. Get submission to find job_batches_rsp_id
            $submission = Submission::select('id', 'job_batches_rsp_id')
                ->where('id', $applicant['submission_id'])
                ->firstOrFail();

            // 2. Get criteria_rating for that job post, with exam criteria
            $criteriaRating = criteria_rating::with(['exams' => function ($query) {
                $query->select('id', 'criteria_rating_id', 'weight', 'description');
            }])
                ->where('job_batches_rsp_id', $submission->job_batches_rsp_id)
                ->first();

            // 3. Compute exam_percentage
            //    Formula: (exam_score / exam_total_score) * weight
            $examPercentage = null;

            if ($criteriaRating && $criteriaRating->exams->isNotEmpty()) {
                $weight = $criteriaRating->exams->first()->weight;

                $examPercentage = ($applicant['exam_score'] / $applicant['exam_total_score']) * $weight;
            }


            // 4. Save — merge computed percentage into the applicant payload

            // $examScore = ApplicantExamScore::create(
            //     ['submission_id' => $applicant['submission_id']],
            //     array_merge($applicant, ['exam_percentage' => $examPercentage])
            // );

            $examScore = ApplicantExamScore::create(
                array_merge(
                    ['submission_id' => $applicant['submission_id']],
                    $applicant,
                    ['exam_percentage' => $examPercentage]
                )
            );

            $results[] = $examScore;

            $this->activityLogService->logAddExamScoreOfApplicant($user, $examScore);
        }

        return response()->json([
            'status'  => true,
            'message' => count($results) . ' applicant exam score(s) saved successfully.',
            'data'    => $results,
        ], 201);
    }

    // update the applicant exam score
    // update the applicant exam score
    public function updateExamScoreOfApplicant($validated, $submissionId)
    {
        // Check if record exists
        $examScore = ApplicantExamScore::where('submission_id', $submissionId)->first();

        if (!$examScore) {
            return response()->json([
                'status'  => false,
                'message' => 'Exam score not found for this submission.',
            ], 404);
        }

        // 1. Get submission to find job_batches_rsp_id
        $submission = Submission::select('id', 'job_batches_rsp_id')
            ->where('id', $submissionId)
            ->firstOrFail();

        // 2. Get criteria_rating for that job post, with exam criteria
        $criteriaRating = criteria_rating::with(['exams' => function ($query) {
            $query->select('id', 'criteria_rating_id', 'weight', 'description');
        }])
            ->where('job_batches_rsp_id', $submission->job_batches_rsp_id)
            ->first();

        // 3. Compute exam_percentage
        //    Formula: (exam_score / exam_total_score) * weight
        $examPercentage = null;

        if ($criteriaRating && $criteriaRating->exams->isNotEmpty()) {
            $weight = $criteriaRating->exams->first()->weight;
            $examPercentage = ($validated['exam_score'] / $validated['exam_total_score']) * $weight;
        }

        $oldValues = $examScore->only([
            'exam_score',
            'exam_total_score',
            'exam_type',
            'exam_date',
            'exam_remarks',
        ]);

        // 4. Update with computed percentage
        $examScore->update(array_merge($validated, ['exam_percentage' => $examPercentage]));

        $updatedExamScore = $examScore->fresh();

        $user = Auth::user();

        // Log the update activity
        $this->activityLogService->logUpdateExamScoreOfApplicant($user, $updatedExamScore, $oldValues);

        return response()->json([
            'status'  => true,
            'message' => 'Applicant exam score updated successfully.',
            'data'    => $updatedExamScore,
        ], 200);
    }

    // list of applicant that dont have yet exam score
    public function applicantDontHaveExamScore($request)
    {
        $search  = $request->input('search');
        $perPage = $request->input('per_page', 10);

        // ✅ Get all submission_ids that already have exam scores
        $hasExamScore = DB::table('applicant_exam_scores')
            ->pluck('submission_id')
            ->toArray();

        // ✅ External applicants — exclude those with exam scores
        $external = Submission::query()
            ->join('nPersonalInfo as p', 'submission.nPersonalInfo_id', '=', 'p.id')
            ->join('job_batches_rsp as jb', 'submission.job_batches_rsp_id', '=', 'jb.id')
            ->whereNotNull('submission.nPersonalInfo_id')
            ->whereNotIn('submission.id', $hasExamScore) // ✅ exclude already scored
            ->select(
                'submission.id as submission_id',
                'p.id as nPersonal_id',
                'p.firstname',
                'p.lastname',
                'jb.id as job_id',
                'jb.position',
                'submission.status',
                DB::raw("'external' as applicant_type"),
                DB::raw("CAST(NULL AS NVARCHAR(50)) as ControlNo")
            );

        if ($search) {
            $external->where(function ($q) use ($search) {
                $q->where('p.firstname', 'like', "%{$search}%")
                    ->orWhere('p.lastname', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(p.firstname,' ',p.lastname) LIKE ?", ["%{$search}%"]);
            });
        }

        // ✅ Internal applicants — exclude those with exam scores
        $internal = Submission::query()
            ->join('xPersonal as xp', 'submission.ControlNo', '=', 'xp.ControlNo')
            ->join('job_batches_rsp as jb', 'submission.job_batches_rsp_id', '=', 'jb.id')
            ->whereNull('submission.nPersonalInfo_id')
            ->whereNotIn('submission.id', $hasExamScore) // ✅ exclude already scored
            ->select(
                'submission.id as submission_id',
                DB::raw("CAST(NULL AS BIGINT) as nPersonal_id"),
                'xp.Firstname as firstname',
                'xp.Surname as lastname',
                'jb.id as job_id',
                'jb.position',
                'submission.status',
                DB::raw("'internal' as applicant_type"),
                'submission.ControlNo'
            );

        if ($search) {
            $internal->where(function ($q) use ($search) {
                $q->where('xp.Firstname', 'like', "%{$search}%")
                    ->orWhere('xp.Surname', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(xp.Firstname,' ',xp.Surname) LIKE ?", ["%{$search}%"])
                    ->orWhere('submission.ControlNo', 'like', "%{$search}%");
            });
        }

        // ✅ Union both and paginate
        $applicants = $external
            ->unionAll($internal)
            ->orderBy('submission_id', 'asc')
            ->paginate($perPage);

        return response()->json($applicants);
    }


    // // list of applicant that have exam score
    public function listOfApplicantWithScore($request)
    {
        $search  = $request->input('search');
        $perPage = $request->input('per_page', 10);

        // ✅ External
        $external = DB::table('submission as s')
            ->join('applicant_exam_scores as aes', 'aes.submission_id', '=', 's.id')
            ->join('nPersonalInfo as p', 's.nPersonalInfo_id', '=', 'p.id')
            ->join('job_batches_rsp as jb', 's.job_batches_rsp_id', '=', 'jb.id')
            ->whereNotNull('s.nPersonalInfo_id')
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('p.firstname', 'like', "%$search%")
                        ->orWhere('p.lastname', 'like', "%$search%");
                });
            })
            ->select([
                'aes.id as exam_score_id',
                's.id as submission_id',
                'p.lastname',
                'p.firstname',
                'jb.position',
                'aes.exam_type',
                'aes.exam_date',
                'aes.exam_score',
                'aes.exam_total_score',
                DB::raw("CONCAT(aes.exam_score, '/', aes.exam_total_score) as exam_result"),
                DB::raw("'external' as applicant_type")
            ]);

        // ✅ Internal
        $internal = DB::table('submission as s')
            ->join('applicant_exam_scores as aes', 'aes.submission_id', '=', 's.id')
            ->join('xPersonal as xp', 's.ControlNo', '=', 'xp.ControlNo')
            ->join('job_batches_rsp as jb', 's.job_batches_rsp_id', '=', 'jb.id')
            ->whereNull('s.nPersonalInfo_id')
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('xp.Firstname', 'like', "%$search%")
                        ->orWhere('xp.Surname', 'like', "%$search%");
                });
            })
            ->select([
                'aes.id as exam_score_id',
                's.id as submission_id',
                'xp.Surname as lastname',
                'xp.Firstname as firstname',
                'jb.position',
                'aes.exam_type',
                'aes.exam_date',
                'aes.exam_score',
                'aes.exam_total_score',
                DB::raw("CONCAT(aes.exam_score, '/', aes.exam_total_score) as exam_result"),
                DB::raw("'internal' as applicant_type")
            ]);

        // ✅ UNION ALL (no DISTINCT = faster)
        $union = $external->unionAll($internal);

        // ✅ Wrap safely
        $query = DB::query()
            ->fromSub($union, 'combined')
            ->orderBy('lastname', 'asc');

        return $query->paginate($perPage);
    }
}
