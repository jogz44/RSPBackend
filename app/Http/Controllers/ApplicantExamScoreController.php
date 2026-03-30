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
            'exam_score' => 'nullable|numeric',
            'exam_details' => 'nullable|string',
            'exam_type' => 'nullable|string',
            'exam_total_score' => 'nullable|integer',
            'exam_date' => 'nullable|string',
            'exam_remarks' => 'nullable|string',
        ]);

        $applicantExamScore = ApplicantExamScore::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Applicant exam score added successfully.',
            'data' => $applicantExamScore
        ], 201);

    }

    public function applicantDontHaveExamScore(Request $request)
    {
        $search  = $request->input('search');
        $perPage = $request->input('per_page', 10);

        // ✅ External applicants — per submission
        $external = Submission::query()
            ->join('nPersonalInfo as p', 'submission.nPersonalInfo_id', '=', 'p.id')
            ->join('job_batches_rsp as jb', 'submission.job_batches_rsp_id', '=', 'jb.id')
            ->whereNotNull('submission.nPersonalInfo_id')
            ->select(
                'submission.id as submission_id',
                'p.id as nPersonal_id',
                'p.firstname',
                'p.lastname',
                'p.date_of_birth',
                'jb.id as job_id',
                'jb.position',          // adjust to your actual column name
                'submission.status',
                'submission.created_at',
                DB::raw("'external' as applicant_type"),
                DB::raw('NULL as ControlNo')
            );

        if ($search) {
            $external->where(function ($q) use ($search) {
                $q->where('p.firstname', 'like', "%{$search}%")
                    ->orWhere('p.lastname', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(p.firstname,' ',p.lastname) LIKE ?", ["%{$search}%"]);
            });
        }

        // ✅ Internal applicants — per submission
        $internal = Submission::query()
            ->join('xPersonal as xp', 'submission.ControlNo', '=', 'xp.ControlNo')
            ->join('job_batches_rsp as jb', 'submission.job_batches_rsp_id', '=', 'jb.id')
            ->whereNull('submission.nPersonalInfo_id')
            ->select(
                'submission.id as submission_id',
                DB::raw('NULL as nPersonal_id'),
                'xp.Firstname as firstname',
                'xp.Surname as lastname',
                'xp.BirthDate as date_of_birth',
                'jb.id as job_id',
                'jb.position',          // adjust to your actual column name
                'submission.status',
                'submission.created_at',
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


}
