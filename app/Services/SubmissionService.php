<?php

namespace App\Services;

use App\Models\excel\nPersonal_info;
use App\Models\Submission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubmissionService
{
    /**
     * Create a new class instance.
     */
    // public function __construct()
    // {
    //     //
    // }
    public function status($validated,$id,$request) // updating the status of the applicant
    {

        // ✅ Update submission in one call
        $submission = Submission::findOrFail($id);
        $submission->update([
            'status' => $validated['status'],

            'education_remark' => $validated['education_remark'] ?? null,
            'experience_remark' => $validated['experience_remark'] ?? null,
            'training_remark' => $validated['training_remark'] ?? null,
            'eligibility_remark' => $validated['eligibility_remark'] ?? null,

            'education_qualification' => $validated['education_qualification'] ?? null,
            'experience_qualification' => $validated['experience_qualification'] ?? null,
            'training_qualification' => $validated['training_qualification'] ?? null,
            'eligibility_qualification' => $validated['eligibility_qualification']?? null,
        ]);


        // ✅ Fetch applicant and job in one shot
        $applicant =nPersonal_info::find($submission->nPersonalInfo_id);

        $externalApplicant = DB::table('xPersonalAddt')
            ->join('xPersonal', 'xPersonalAddt.ControlNo', '=', 'xPersonal.ControlNo')
            ->where('xPersonalAddt.ControlNo', $submission->ControlNo)
            ->select('xPersonalAddt.*', 'xPersonal.Firstname', 'xPersonal.Surname', 'xPersonalAddt.EmailAdd')
            ->first();

        $activeApplicant = $applicant ?? $externalApplicant;

        if (!$activeApplicant) {
            // Log::warning("⚠️ No applicant found for submission ID: {$id}");
            return response()->json([
                'message' => 'Submission updated, but applicant not found for email notification.',
                'data' => $submission
            ]);
        }

        $fullname = $activeApplicant instanceof nPersonal_info
            ? trim("{$activeApplicant->firstname} {$activeApplicant->lastname}")
            : trim("{$activeApplicant->Firstname} {$activeApplicant->Surname}");

        // ➕ ADD ACTIVITY LOG HERE
        $user = Auth::user();

        if ($user instanceof \App\Models\User) {
            activity('Applicant Evaluation')
                ->causedBy($user)
                ->performedOn($submission)
                ->withProperties([
                    'name' => $user->name,
                    'username' => $user->username,
                    'position' => $user->position,
                    'submission_id' => $submission->id,
                    'applicant_name' => $fullname,
                    'status_updated_to' => $validated['status'],
                'education_remark' => $validated['education_remark'] ?? null,
                'experience_remark' => $validated['experience_remark'] ?? null,
                'training_remark' => $validated['training_remark'] ?? null,
                'eligibility_remark' => $validated['eligibility_remark'] ?? null,
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
                ])
                ->log("'{$user->name}' evaluated '{$fullname}' submission.");
        }

        return response()->json([
            'message' => 'Evaluation successfully saved and email notification processed.',
            'data' => $submission
        ]);
    }


}
