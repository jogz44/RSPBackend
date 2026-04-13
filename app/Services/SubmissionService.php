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

    //  getting the image of the internal employee applicant
    public function proxyImage($submission_id)
    {
        $submission = Submission::find($submission_id);
        if (!$submission) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Get image_path from employee DB
        $xPDS = new \App\Http\Controllers\xPDSController();
        $employeeData = $xPDS->getPersonalDataSheet(
            new \Illuminate\Http\Request(['controlno' => $submission->ControlNo])
        );
        $employeeJson = $employeeData->getData(true);
        $imagePath = $employeeJson['User'][0]['Pics'] ?? null;

        if (!$imagePath) {
            return response()->json(['message' => 'No image found'], 404);
        }

        // Convert UNC path \\server\share\... to a readable path
        $localPath = str_replace('\\', '/', $imagePath);
        // \\192.168.2.205\Payroll Database\... => //192.168.2.205/Payroll Database/...
        // On Linux server, UNC paths can be mounted — adjust this to your mount point
        // e.g. if mounted at /mnt/payroll:
        // $localPath = str_replace('//192.168.2.205/Payroll Database', '/mnt/payroll', $localPath);

        if (!file_exists($localPath)) {
            return response()->json(['message' => 'Image file not found on server'], 404);
        }

        $mimeType = mime_content_type($localPath) ?: 'image/jpeg';
        $imageData = file_get_contents($localPath);

        return response($imageData, 200)->header('Content-Type', $mimeType);
    }


}
