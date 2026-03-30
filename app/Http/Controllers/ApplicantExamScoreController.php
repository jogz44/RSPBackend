<?php

namespace App\Http\Controllers;

use App\Models\ApplicantExamScore;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpParser\Node\Expr\FuncCall;

class ApplicantExamScoreController extends Controller
{
    //

    // store the applicant exam score
    public function addExamScoreOfApplicant(Request $request)
    {
        $validated = $request->validate([
            'submission_id' => 'required|exists:submission,id',
            'exam_score' => 'required|numeric',
            'exam_details' => 'required|string',
            'exam_type' => 'required|string',
            'exam_total_score' => 'required|integer',
            'exam_date' => 'required|string',
            'exam_remarks' => 'required|string',
        ]);

        $applicantExamScore = ApplicantExamScore::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Applicant exam score added successfully.',
            'data' => $applicantExamScore
        ], 201);

    }

    // list of applicant that dont have yet exam score
    public function applicantDontHaveExamScore(Request $request)
    {
        $search  = $request->input('search');
        $perPage = $request->input('per_page', 20);

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


    // list of applicant that have exam score
    public function listOfApplicantHaveScore(Request $request)
    {
        $search  = $request->input('search');
        $perPage = $request->input('per_page', 10);

        // ✅ External applicants with exam score
        $external = DB::table('applicant_exam_scores as aes')
            ->join('submission', 'submission.id', '=', 'aes.submission_id')
            ->join('nPersonalInfo as p', 'submission.nPersonalInfo_id', '=', 'p.id')
            ->join('job_batches_rsp as jb', 'submission.job_batches_rsp_id', '=', 'jb.id')
            ->whereNotNull('submission.nPersonalInfo_id')
            ->select(
                'aes.id as exam_score_id',
                'submission.id as submission_id',
                'p.lastname',
                'p.firstname',
                'jb.position',
                'aes.exam_type',
                'aes.exam_date',
                DB::raw("'external' as applicant_type")
            );

        if ($search) {
            $external->where(function ($q) use ($search) {
                $q->where('p.firstname', 'like', "%{$search}%")
                    ->orWhere('p.lastname', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(p.firstname,' ',p.lastname) LIKE ?", ["%{$search}%"]);
            });
        }

        // ✅ Internal applicants with exam score
        $internal = DB::table('applicant_exam_scores as aes')
            ->join('submission', 'submission.id', '=', 'aes.submission_id')
            ->join('xPersonal as xp', 'submission.ControlNo', '=', 'xp.ControlNo')
            ->join('job_batches_rsp as jb', 'submission.job_batches_rsp_id', '=', 'jb.id')
            ->whereNull('submission.nPersonalInfo_id')
            ->select(
                'aes.id as exam_score_id',
                'submission.id as submission_id',
                'xp.Surname as lastname',
                'xp.Firstname as firstname',
                'jb.position',
                'aes.exam_type',
                'aes.exam_date',
                DB::raw("'internal' as applicant_type")
            );

        if ($search) {
            $internal->where(function ($q) use ($search) {
                $q->where('xp.Firstname', 'like', "%{$search}%")
                    ->orWhere('xp.Surname', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(xp.Firstname,' ',xp.Surname) LIKE ?", ["%{$search}%"]);
            });
        }

        $applicants = $external
            ->unionAll($internal)
            ->orderBy('lastname', 'asc')
            ->paginate($perPage);

        return response()->json($applicants);
    }
}
