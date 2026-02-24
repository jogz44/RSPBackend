<?php

namespace App\Services;

use App\Mail\EmailApi;
use App\Models\JobBatchesRsp;
use App\Models\Schedule;
use App\Models\SchedulesApplicant;
use App\Models\Submission;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ScheduleService
{
    /**
     * Create a new class instance.
     */
    // public function __construct()
    // {
    //     //
    // }

    public function sendEmailInterview($validated)
    {

        $date = Carbon::parse($validated['date_interview'])->format('F d, Y');
        $timeFormatted = Carbon::parse($validated['time_interview'])->format('g:i A');
        $time = $validated['time_interview']; // store this in DB

        $venue = $validated['venue_interview'];
        $batchName = $validated['batch_name'];

        $count = 0;

        /** ✅ CREATE ONE SCHEDULE ONLY */
        $schedule = Schedule::create([
            'batch_name' => $batchName,
            'date_interview' => $validated['date_interview'],
            'time_interview' => $time,
            'venue_interview' => $venue,
        ]);

        foreach ($validated['applicants'] as $app) {

            $submission = Submission::with('nPersonalInfo')->find($app['submission_id']);
            if (!$submission) continue;

            $job = JobBatchesRsp::find($app['job_batches_rsp']);
            if (!$job) continue;

            $position = $job->Position ?? 'the applied position';
            $office = $job->Office ?? 'the corresponding office';
            $SalaryGrade = $job->SalaryGrade ?? 'the corresponding SG';

            // Get applicant info
            if ($submission->nPersonalInfo_id) {
                $firstname = $submission->nPersonalInfo->firstname;
                $lastname  = $submission->nPersonalInfo->lastname;
                $email     = $submission->nPersonalInfo->email_address ?? null;
            } else if ($submission->ControlNo) {
                $employee = DB::table('xPersonalAddt')
                    ->join('xPersonal', 'xPersonalAddt.ControlNo', '=', 'xPersonal.ControlNo')
                    ->where('xPersonalAddt.ControlNo', $submission->ControlNo)
                    ->select('xPersonalAddt.*', 'xPersonal.Firstname', 'xPersonal.Surname', 'xPersonalAddt.EmailAdd')
                    ->first();

                if (!$employee) continue;

                $firstname = $employee->Firstname;
                $lastname = $employee->Surname;
                $email = $employee->EmailAdd;
            } else {
                continue;
            }

            $fullname = trim("$firstname $lastname");
            // if (!$email) continue;
            if (!$email) {
                Log::info("Skipping applicant {$submission->id}, email not found");
                continue;
            }

            /** link applicant to schedule */
            SchedulesApplicant::create([
                'schedule_id' => $schedule->id,
                'submission_id' => $submission->id,
            ]);

            /** send email */
            Mail::to($email)->queue(new EmailApi(
                "Interview Invitation",
                'mail-template.interview',
                [

                    'fullname' => $fullname,
                    'date' => $date,
                    'time' => $time,
                    'venue' => $venue,
                    'position' => $position,
                    'SalaryGrade' => $SalaryGrade,
                    'office' => $office,
                ]
            ));

            $count++;
        }

        return response()->json([
            'success' => true,
            'message' => "Interview invitations successfully sent to {$count} applicant(s).",
        ]);
    }


    // for the unqualified applicant that send an  the qualification and remarks
    public function sendEmailApplicantBatch($validated, $request)
    {

        $jobId = $validated['job_batches_rsp_id'];

        // Get ONLY Unqualified applicants
        $submissions = Submission::where('job_batches_rsp_id', $jobId)
            ->with('nPersonalInfo')
            ->where('status', 'Unqualified')
            ->get();

        if ($submissions->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No Unqualified applicants found for this job post.'
            ], 404);
        }

        // Get job details
        $job = \App\Models\JobBatchesRsp::with('criteria:id,job_batches_rsp_id,Education,Eligibility,Training,Experience')
            ->find($jobId);

        $position = $job->Position ?? 'the applied position';
        $office = $job->Office ?? 'the corresponding office';

        // QS of the job post
        $education_qs = $job->criteria->Education ?? 'N/A';
        $eligibility_qs = $job->criteria->Eligibility ?? 'N/A';
        $training_qs = $job->criteria->Training ?? 'N/A';
        $experience_qs = $job->criteria->Experience ?? 'N/A';

        $count = 0;

        foreach ($submissions as $submission) {
            $applicant = $submission->nPersonalInfo;

            // Check internal/external records
            $externalApplicant = DB::table('xPersonalAddt')
                ->join('xPersonal', 'xPersonalAddt.ControlNo', '=', 'xPersonal.ControlNo')
                ->where('xPersonalAddt.ControlNo', $submission->ControlNo)
                ->select('xPersonalAddt.*', 'xPersonal.Firstname', 'xPersonal.Surname', 'xPersonalAddt.EmailAdd', 'xPersonalAddt.Rstreet', 'xPersonalAddt.Rbarangay', 'xPersonalAddt.Rcity', 'xPersonalAddt.Rprovince')
                ->first();

            $activeApplicant = $applicant ?? $externalApplicant;

            if (!$activeApplicant) {
                Log::warning("⚠️ No applicant record found for submission ID: {$submission->id}");
                continue;
            }

            // Email
            $email = $applicant->email_address ?? $externalApplicant->EmailAdd ?? null;

            // Fullname
            $fullname = $applicant
                ? trim("{$applicant->firstname} {$applicant->lastname}")
                : trim("{$externalApplicant->Firstname} {$externalApplicant->Surname}");

            if (empty($email)) {
                Log::warning("⚠️ Applicant {$fullname} has no email address.");
                continue;
            }
            $isInternal = !is_null($submission->nPersonalInfo_id);

 
            if ($isInternal) {
                // INTERNAL
                $educationRecords  = $submission->getEducationRecordsInternal();
                $experienceRecords = $submission->getExperienceRecordsInternal();
                $trainingRecords   = $submission->getTrainingRecordsInternal();
                $eligibilityRecords = $submission->getEligibilityRecordsInternal();

                $educationText  = $this->formatEducationForEmailInternal($educationRecords);
                $experienceText = $this->formatExperienceForEmailInternal($experienceRecords);
                $trainingText   = $this->formatTrainingForEmailInternal($trainingRecords);
                $eligibilityText = $this->formatEligibilityForEmailInternal($eligibilityRecords);
            } else {
                // EXTERNAL
                $educationRecords  = $submission->getEducationRecordsExternal();
                $experienceRecords = $submission->getExperienceRecordsExternal();
                $trainingRecords   = $submission->getTrainingRecordsExternal();
                $eligibilityRecords = $submission->getEligibilityRecordsExternal();

                $educationText  = $this->formatEducationForEmailExternal($educationRecords);
                $experienceText = $this->formatExperienceForEmailExternal($experienceRecords);
                $trainingText   = $this->formatTrainingForEmailExternal($trainingRecords);
                $eligibilityText = $this->formatEligibilityForEmailExternal($eligibilityRecords);
            }


            $template = 'mail-template.unqualified';

            try {
                Mail::to($email)->queue(
                    new EmailApi(
                        "Application - Unqualified",
                        $template,
                        [
                            'fullname' => $fullname,
                            'lastname' => $applicant->lastname ?? $externalApplicant->Surname ?? 'N/A',
                            'street' => $applicant->residential_street ?? $externalApplicant->Rstreet ?? 'N/A',
                            'barangay' => $applicant->residential_barangay ?? $externalApplicant->Rbarangay ?? 'N/A',
                            'city' => $applicant->residential_city ?? $externalApplicant->Rcity ?? 'N/A',
                            'province' => $applicant->residential_province ?? $externalApplicant->Rprovince ?? 'N/A',
                            'position' => $position,
                            'office' => $office,

                            // ✅ FORMATTED QUALIFICATION TEXT (matching blade variable names)
                            'education_qualification' => $educationText,
                            'experience_qualification' => $experienceText,
                            'training_qualification' => $trainingText,
                            'eligibility_qualification' => $eligibilityText,

                            // Remarks
                            'education_remark' => $submission->education_remark ?? 'N/A',
                            'experience_remark' => $submission->experience_remark ?? 'N/A',
                            'training_remark' => $submission->training_remark ?? 'N/A',
                            'eligibility_remark' => $submission->eligibility_remark ?? 'N/A',

                            // QS of job post
                            'education_qs' => $education_qs,
                            'eligibility_qs' => $eligibility_qs,
                            'training_qs' => $training_qs,
                            'experience_qs' => $experience_qs,

                            'date' => now()->format('F d, Y'),
                        ]
                    )
                );

                // Log::info("📧 Queued UNQUALIFIED email for {$fullname} ({$email}).");

                $user = Auth::user();
                if ($user instanceof \App\Models\User) {
                    activity('Unqualified Applicant Email Sent')
                        ->causedBy($user)
                        ->performedOn($submission)
                        ->withProperties([
                            'name'     => $user->name,
                            'username'       => $user->username,
                            'applicant_name' => $fullname,
                            'email'          => $email,
                            'job_post_id'    => $jobId,
                            'position'       => $position,
                            'office'         => $office,
                            'date'           => now()->format('F d, Y'),
                            'ip' => $request->ip(),
                            'user_agent' => $request->header('User-Agent'),
                            'education_remark'   => $submission->education_remark ?? 'N/A',
                            'experience_remark'  => $submission->experience_remark ?? 'N/A',
                            'training_remark'    => $submission->training_remark ?? 'N/A',
                            'eligibility_remark' => $submission->eligibility_remark ?? 'N/A',
                        ])
                        ->log("{$user->name} sent an unqualified notification to {$fullname} for the {$position} position in {$office}.");
                }


                $count++;
            } catch (\Exception $e) {
                Log::error("❌ Failed to send email for {$fullname}: {$e->getMessage()}");
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Unqualified email notifications sent to {$count} applicant(s)."
        ]);
    }

    // ✅ Helper method to format education
    private function formatEducationForEmailInternal($educationRecords)
    {
        if ($educationRecords->isEmpty()) {
            return 'No relevant education based on the specific requirement of the position.';
        }

        $formatted = [];
        foreach ($educationRecords as $edu) {
            $degree = $edu->degree ?? 'N/A';
            $school = $edu->school_name ?? 'N/A';
            $year = $edu->year_graduated ?? 'N/A';
            $formatted[] = "• {$degree} at {$school} ({$year})";
        }

        return implode('<br>', $formatted);
    }


    // ✅ Helper method to format experience
    private function formatExperienceForEmailInternal($experienceRecords)
    {
        if ($experienceRecords->isEmpty()) {
            return 'No relevant experience based on the specific requirement of the position.';
        }

        $formatted = [];
        foreach ($experienceRecords as $exp) {
            $position = $exp->position_title ?? 'N/A';
            $department = $exp->department ?? 'N/A';
            $dateFrom = $exp->work_date_from ?? 'N/A';
            $dateTo = $exp->work_date_to ?? 'N/A';
            $formatted[] = "• {$position} at {$department} ({$dateFrom} - {$dateTo})";
        }

        return implode('<br>', $formatted);
    }


    // ✅ Helper method to format training
    private function formatTrainingForEmailInternal($trainingRecords)
    {
        if ($trainingRecords->isEmpty()) {
            return 'No relevant training based on the specific requirement of the position.';
        }

        $formatted = [];
        foreach ($trainingRecords as $training) {
            $title = $training->training_title ?? 'N/A';
            $hours = $training->number_of_hours ?? 'N/A';
            $formatted[] = "• {$title} ({$hours} hours)";
        }

        return implode('<br>', $formatted);
    }

    // ✅ Helper method to format eligibility
    private function formatEligibilityForEmailInternal($eligibilityRecords)
    {
        if ($eligibilityRecords->isEmpty()) {
            return 'No relevant eligibility based on the specific requirement of the position.';
        }

        $formatted = [];
        foreach ($eligibilityRecords as $eligibility) {
            $name = $eligibility->eligibility ?? 'N/A';
            $rating = $eligibility->rating ? " - Rating: {$eligibility->rating}" : '';
            $formatted[] = "• {$name}{$rating}";
        }

        return implode('<br>', $formatted);
    }

    // external
    // formatting helpers for qualified applicants for the external
    // Helper method to format education
    private function formatEducationForEmailExternal($educationRecords)
    {
        if ($educationRecords->isEmpty()) {
            return 'No relevant education based on the specific requirement of the position.';
        }

        $formatted = [];
        foreach ($educationRecords as $edu) {
            $degree = $edu->Degree ?? 'N/A';
            // $school = $edu->School ?? 'N/A';
            $unit = $edu->NumUnits ?? 'N/A';
            $formatted[] = "• {$degree} ({$unit} units)";
        }

        return implode('<br>', $formatted);
    }


    // ✅ Helper method to format experience
    private function formatExperienceForEmailExternal($experienceRecords)
    {
        if ($experienceRecords->isEmpty()) {
            return 'No relevant experience based on the specific requirement of the position.';
        }

        $formatted = [];
        foreach ($experienceRecords as $exp) {
            $position = $exp->Wposition ?? 'N/A';
            $department = $exp->WCompany ?? 'N/A';
            $dateFrom = $exp->WFrom ?? 'N/A';
            $dateTo = $exp->WTo ?? 'N/A';
            $formatted[] = "• {$position} at {$department} ({$dateFrom} - {$dateTo})";
        }

        return implode('<br>', $formatted);
    }


    private function formatTrainingForEmailExternal($trainingRecords)
    {
        if ($trainingRecords->isEmpty()) {
            return 'No relevant training based on the specific requirement of the position.';
        }

        $formatted = [];
        foreach ($trainingRecords as $training) {
            $title = $training->Training ?? 'N/A';
            $hours = $training->NumHours ?? 'N/A';
            $formatted[] = "• {$title} ({$hours} hours)";
        }

        return implode('<br>', $formatted);
    }



    private function formatEligibilityForEmailExternal($eligibilityRecords)
    {
        if ($eligibilityRecords->isEmpty()) {
            return 'No relevant eligibility based on the specific requirement of the position.';
        }

        $formatted = [];

        foreach ($eligibilityRecords as $eligibility) {
            $name = $eligibility->CivilServe ?? 'N/A';

            // ✅ use Rates safely
            $rating = !empty($eligibility->Rates)
                ? " - Rating: {$eligibility->Rates}"
                : '';

            $formatted[] = "• {$name}{$rating}";
        }

        return implode('<br>', $formatted);
    }
}
