<?php

namespace App\Services;

use App\Models\ApplicantExamScore;
use App\Models\Submission;
use Illuminate\Support\Facades\DB;

class ApplicantExamScoreService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    // store the applicant exam score
    public function addExamScoreOfApplicant($validated)
    {
        $results = [];

        foreach ($validated['applicants'] as $applicant) {
            $results[] = ApplicantExamScore::updateOrCreate(
                ['submission_id' => $applicant['submission_id']], // avoid duplicates
                $applicant
            );
        }

        return response()->json([
            'status'  => true,
            'message' => count($results) . ' applicant exam score(s) saved successfully.',
            'data'    => $results,
        ], 201);
    }

    // update the applicant exam score
    public function updateExamScoreOfApplicant($validated, $submissionId)
    {
        //  Check if record exists
        $examScore = ApplicantExamScore::where('submission_id', $submissionId)->first();

        if (!$examScore) {
            return response()->json([
                'status'  => false,
                'message' => 'Exam score not found for this submission.',
            ], 404);
        }

        // ✅ Update and return the actual updated record
        $examScore->update($validated);

        return response()->json([
            'status'  => true,
            'message' => 'Applicant exam score updated successfully.',
            'data'    => $examScore->fresh(), // returns updated record
        ], 200); //  200 for update
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
    // public function listOfApplicantWithScore($request)
    // {
    //     $search  = $request->input('search');
    //     $perPage = $request->input('per_page', 10);

    //     // ✅ External applicants with exam score
    //     $external = DB::table('applicant_exam_scores as aes')
    //         ->join('submission', 'submission.id', '=', 'aes.submission_id')
    //         ->join('nPersonalInfo as p', 'submission.nPersonalInfo_id', '=', 'p.id')
    //         ->join('job_batches_rsp as jb', 'submission.job_batches_rsp_id', '=', 'jb.id')
    //         ->whereNotNull('submission.nPersonalInfo_id')
    //         ->select(
    //             'aes.id as exam_score_id',
    //             'submission.id as submission_id',
    //             'p.lastname',
    //             'p.firstname',
    //             'jb.position',
    //             'aes.exam_type',
    //             'aes.exam_date',
    //             'aes.exam_score',
    //             'aes.exam_total_score',
    //             DB::raw("CONCAT(aes.exam_score, '/', aes.exam_total_score) as exam_result"), // 👈
    //             DB::raw("'external' as applicant_type")
    //         );

    //     if ($search) {
    //         $external->where(function ($q) use ($search) {
    //             $q->where('p.firstname', 'like', "%{$search}%")
    //                 ->orWhere('p.lastname', 'like', "%{$search}%")
    //                 ->orWhereRaw("CONCAT(p.firstname,' ',p.lastname) LIKE ?", ["%{$search}%"]);
    //         });
    //     }

    //     // ✅ Internal applicants with exam score
    //     $internal = DB::table('applicant_exam_scores as aes')
    //         ->join('submission', 'submission.id', '=', 'aes.submission_id')
    //         ->join('xPersonal as xp', 'submission.ControlNo', '=', 'xp.ControlNo')
    //         ->join('job_batches_rsp as jb', 'submission.job_batches_rsp_id', '=', 'jb.id')
    //         ->whereNull('submission.nPersonalInfo_id')
    //         ->select(
    //             'aes.id as exam_score_id',
    //             'submission.id as submission_id',
    //             'xp.Surname as lastname',
    //             'xp.Firstname as firstname',
    //             'jb.position',
    //             'aes.exam_type',
    //             'aes.exam_date',
    //             'aes.exam_score',
    //             'aes.exam_total_score',
    //             DB::raw("CONCAT(aes.exam_score, '/', aes.exam_total_score) as exam_result"), // 👈
    //             DB::raw("'internal' as applicant_type")
    //         );

    //     if ($search) {
    //         $internal->where(function ($q) use ($search) {
    //             $q->where('xp.Firstname', 'like', "%{$search}%")
    //                 ->orWhere('xp.Surname', 'like', "%{$search}%")
    //                 ->orWhereRaw("CONCAT(xp.Firstname,' ',xp.Surname) LIKE ?", ["%{$search}%"]);
    //         });
    //     }

    //     $applicants = $external
    //         ->unionAll($internal)
    //         ->orderBy('lastname', 'asc')
    //         ->paginate($perPage);

    //     return response()->json($applicants);
    // }

    // public function listOfApplicantWithScore($request)
    // {
    //     $search  = $request->input('search');
    //     $perPage = $request->input('per_page', 10);

    //     // ✅ External query
    //     $external = DB::table('applicant_exam_scores as aes')
    //         ->join('submission', 'submission.id', '=', 'aes.submission_id')
    //         ->join('nPersonalInfo as p', 'submission.nPersonalInfo_id', '=', 'p.id')
    //         ->join('job_batches_rsp as jb', 'submission.job_batches_rsp_id', '=', 'jb.id')
    //         ->whereNotNull('submission.nPersonalInfo_id')
    //         ->select(
    //             'aes.id as exam_score_id',
    //             'submission.id as submission_id',
    //             'p.lastname',
    //             'p.firstname',
    //             'jb.position',
    //             'aes.exam_type',
    //             'aes.exam_date',
    //             'aes.exam_score',
    //             'aes.exam_total_score',
    //             DB::raw("CONCAT(aes.exam_score, '/', aes.exam_total_score) as exam_result"),
    //             DB::raw("'external' as applicant_type")
    //         );

    //     if ($search) {
    //         $external->where(function ($q) use ($search) {
    //             $q->where('p.firstname', 'like', "%{$search}%")
    //                 ->orWhere('p.lastname', 'like', "%{$search}%")
    //                 ->orWhereRaw("CONCAT(p.firstname,' ',p.lastname) LIKE ?", ["%{$search}%"]);
    //         });
    //     }

    //     // ✅ Internal query
    //     $internal = DB::table('applicant_exam_scores as aes')
    //         ->join('submission', 'submission.id', '=', 'aes.submission_id')
    //         ->join('xPersonal as xp', 'submission.ControlNo', '=', 'xp.ControlNo')
    //         ->join('job_batches_rsp as jb', 'submission.job_batches_rsp_id', '=', 'jb.id')
    //         ->whereNull('submission.nPersonalInfo_id')
    //         ->select(
    //             'aes.id as exam_score_id',
    //             'submission.id as submission_id',
    //             'xp.Surname as lastname',
    //             'xp.Firstname as firstname',
    //             'jb.position',
    //             'aes.exam_type',
    //             'aes.exam_date',
    //             'aes.exam_score',
    //             'aes.exam_total_score',
    //             DB::raw("CONCAT(aes.exam_score, '/', aes.exam_total_score) as exam_result"),
    //             DB::raw("'internal' as applicant_type")
    //         );

    //     if ($search) {
    //         $internal->where(function ($q) use ($search) {
    //             $q->where('xp.Firstname', 'like', "%{$search}%")
    //                 ->orWhere('xp.Surname', 'like', "%{$search}%")
    //                 ->orWhereRaw("CONCAT(xp.Firstname,' ',xp.Surname) LIKE ?", ["%{$search}%"]);
    //         });
    //     }

    //     // ✅ Wrap the union as a subquery, THEN paginate
    //     $union = $external->unionAll($internal);

    //     $applicants = DB::table(DB::raw("({$union->toSql()}) as combined"))
    //         ->mergeBindings($union)
    //         ->orderBy('lastname', 'asc')
    //         ->paginate($perPage);

    //     return response()->json($applicants);
    // }

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
