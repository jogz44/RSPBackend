<?php

namespace App\Services;

use App\Jobs\SendApplicantSms;
use App\Mail\EmailApi;
use App\Models\EmailLog;
use App\Models\JobBatchesRsp;
use App\Models\Schedule;
use App\Models\SchedulesApplicant;
use App\Models\SchedulesExam;
use App\Models\SchedulesExamApplicant;
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

    // ── SEND EMAIL INTERVIEW ─────────────────────────────────────────────
    public function sendEmailInterview($validated)
    {
        $date          = Carbon::parse($validated['date_interview'])->format('F d, Y');
        $timeFormatted = Carbon::parse($validated['time_interview'])->format('g:i A');
        $time          = $validated['time_interview'];
        $venue         = $validated['venue_interview'];
        $batchName     = $validated['batch_name'];
        $count         = 0;

        $schedule = Schedule::create([
            'batch_name'      => $batchName,
            'date_interview'  => $validated['date_interview'],
            'time_interview'  => $time,
            'venue_interview' => $venue,
        ]);

        foreach ($validated['applicants'] as $app) {

            $submission = Submission::with('nPersonalInfo')->find($app['submission_id']);
            if (!$submission) continue;

            $job = JobBatchesRsp::find($app['job_batches_rsp']);
            if (!$job) continue;

            $position    = $job->Position    ?? '';
            $office      = $job->Office      ?? '';
            $SalaryGrade = $job->SalaryGrade ?? '';
            $ItemNo      = $job->ItemNo      ?? '';

            [$fullname, $email, $contactNumber] = $this->resolveApplicantInfo($submission);

            if (!$email) {
                Log::info("Skipping applicant {$submission->id}, email not found");
                continue;
            }

            SchedulesApplicant::create([
                'schedule_id'   => $schedule->id,
                'submission_id' => $submission->id,
            ]);

            // ✅ Send Email
            Mail::to($email)->queue((new EmailApi(
                "Interview Invitation",
                'mail-template.interview',
                [
                    'fullname'    => $fullname,
                    'date'        => $date,
                    'time'        => $timeFormatted,
                    'venue'       => $venue,
                    'position'    => $position,
                    'SalaryGrade' => $SalaryGrade,
                    'office'      => $office,
                    'ItemNo'      => $ItemNo,
                ]
            ))->onQueue('emails'));

            // ✅ Send SMS
            $this->dispatchSms(
                contactNumber: $contactNumber,
                fullname: $fullname,
                type: 'interview',
                date: $date,
                time: $timeFormatted,
                venue: $venue,
                position: $position,
                office: $office,
                ItemNo: $ItemNo,
            );

            $count++;

            EmailLog::create([
                'email'    => $email,
                'activity' => 'Interview invitations',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Interview invitations successfully sent to {$count} applicant(s).",
        ]);
    }


    public function updateEmailInterview($validated, $scheduleId)
    {
        $schedule = Schedule::findOrFail($scheduleId);

        // ✅ Normalize before comparing — prevents false positives from format mismatches
        $dateChanged = Carbon::parse($schedule->date_interview)->format('Y-m-d') !== Carbon::parse($validated['date_interview'])->format('Y-m-d')
            || Carbon::parse($schedule->time_interview)->format('H:i')   !== Carbon::parse($validated['time_interview'])->format('H:i')
            || trim($schedule->venue_interview)                           !== trim($validated['venue_interview']);

        // Get existing applicants BEFORE updating
        $existingSubmissionIds = SchedulesApplicant::where('schedule_id', $schedule->id)
            ->pluck('submission_id')
            ->toArray();

        $schedule->update([
            'batch_name'      => $validated['batch_name'],
            'date_interview'  => $validated['date_interview'],
            'time_interview'  => $validated['time_interview'],
            'venue_interview' => $validated['venue_interview'],
        ]);

        $date          = Carbon::parse($validated['date_interview'])->format('F d, Y');
        $timeFormatted = Carbon::parse($validated['time_interview'])->format('g:i A');
        $venue         = $validated['venue_interview'];
        $newCount      = 0;
        $oldCount      = 0;

        // ─── STEP 1: Handle new applicants (if any) ───────────────────────
        if (!empty($validated['applicants'])) {
            foreach ($validated['applicants'] as $app) {

                $submission = Submission::with('nPersonalInfo')->find($app['submission_id']);
                if (!$submission) continue;

                $job = JobBatchesRsp::find($app['job_batches_rsp']);
                if (!$job) continue;

                $isNew = !in_array($submission->id, $existingSubmissionIds);

                SchedulesApplicant::firstOrCreate([
                    'schedule_id'   => $schedule->id,
                    'submission_id' => $submission->id,
                ]);

                if (!$isNew) continue; // already exists, skip

                $position    = $job->Position    ?? '';
                $office      = $job->Office      ?? '';
                $SalaryGrade = $job->SalaryGrade ?? '';
                $ItemNo      = $job->ItemNo      ?? '';

                [$fullname, $email, $contactNumber] = $this->resolveApplicantInfo($submission);

                if (!$email) {
                    Log::info("Skipping new applicant {$submission->id}, email not found");
                    continue;
                }

                Mail::to($email)->queue((new EmailApi(
                    "Interview Invitation",
                    'mail-template.interview',
                    [
                        'fullname'    => $fullname,
                        'date'        => $date,
                        'time'        => $timeFormatted,
                        'venue'       => $venue,
                        'position'    => $position,
                        'SalaryGrade' => $SalaryGrade,
                        'office'      => $office,
                        'ItemNo'      => $ItemNo,
                    ]
                ))->onQueue('emails'));

                $this->dispatchSms(
                    contactNumber: $contactNumber,
                    fullname: $fullname,
                    type: 'interview',
                    date: $date,
                    time: $timeFormatted,
                    venue: $venue,
                    position: $position,
                    office: $office,
                    ItemNo: $ItemNo,
                );

                $newCount++;

                EmailLog::create([
                    'email'    => $email,
                    'activity' => 'Interview invitations',
                ]);
            }
        }

        // ─── STEP 2: Notify OLD applicants ONLY if date/time/venue changed ─
        if ($dateChanged && !empty($existingSubmissionIds)) {
            $existingApplicants = SchedulesApplicant::where('schedule_id', $schedule->id)
                ->whereIn('submission_id', $existingSubmissionIds)
                ->with(['submission.nPersonalInfo'])
                ->get();

            foreach ($existingApplicants as $scheduleApplicant) {
                $submission = $scheduleApplicant->submission;
                if (!$submission) continue;

                $job = JobBatchesRsp::find($submission->job_batches_rsp_id);
                if (!$job) {
                    Log::info("Skipping existing applicant {$submission->id}, job not found");
                    continue;
                }

                $position    = $job->Position    ?? '';
                $office      = $job->Office      ?? '';
                $SalaryGrade = $job->SalaryGrade ?? '';
                $ItemNo      = $job->ItemNo      ?? '';

                [$fullname, $email, $contactNumber] = $this->resolveApplicantInfo($submission);

                if (!$email) {
                    Log::info("Skipping existing applicant {$submission->id}, email not found");
                    continue;
                }

                Mail::to($email)->queue((new EmailApi(
                    "Interview Re-Schedule",
                    'mail-template.interview-reschedule',
                    [
                        'fullname'    => $fullname,
                        'date'        => $date,
                        'time'        => $timeFormatted,
                        'venue'       => $venue,
                        'position'    => $position,
                        'SalaryGrade' => $SalaryGrade,
                        'office'      => $office,
                        'ItemNo'      => $ItemNo,
                    ]
                ))->onQueue('emails'));

                $this->dispatchSms(
                    contactNumber: $contactNumber,
                    fullname: $fullname,
                    type: 'interview-reschedule',
                    date: $date,
                    time: $timeFormatted,
                    venue: $venue,
                    position: $position,
                    office: $office,
                    ItemNo: $ItemNo,
                );

                $oldCount++;

                EmailLog::create([
                    'email'    => $email,
                    'activity' => 'Interview re-schedule invitations',
                ]);
            }
        }

        // ─── Response ─────────────────────────────────────────────────────
        $messages = [];
        if ($oldCount > 0) $messages[] = "Re-schedule notice sent to {$oldCount} existing applicant(s).";
        if ($newCount > 0) $messages[] = "Interview invitation sent to {$newCount} new applicant(s).";
        if (empty($messages)) $messages[] = "Schedule updated successfully. No emails were sent.";

        return response()->json([
            'success' => true,
            'message' => implode(' ', $messages),
        ]);
    }

    // cancel interview and send email to applicant
    public function cancelEmailInterview($scheduleId)
    {
        $schedule = Schedule::findOrFail($scheduleId);

        // ✅ Get schedule details for the email
        $date          = Carbon::parse($schedule->date_interview)->format('F d, Y');
        $timeFormatted = Carbon::parse($schedule->time_interview)->format('g:i A');
        $venue         = $schedule->venue_interview;
        $count         = 0;

        // ✅ Get ALL existing applicants for this schedule
        $scheduleApplicants = SchedulesApplicant::where('schedule_id', $schedule->id)
            ->with(['submission.nPersonalInfo'])
            ->get();

        if ($scheduleApplicants->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No applicants found for this schedule.',
            ], 404);
        }

        foreach ($scheduleApplicants as $scheduleApplicant) {
            $submission = $scheduleApplicant->submission;
            if (!$submission) continue;

            // ✅ Get job from submission
            $job = JobBatchesRsp::find($submission->job_batches_rsp_id);
            if (!$job) {
                Log::info("Skipping applicant {$submission->id}, job not found");
                continue;
            }

            $position    = $job->Position    ?? '';
            $office      = $job->Office      ?? '';
            $SalaryGrade = $job->SalaryGrade ?? '';
            $ItemNo      = $job->ItemNo      ?? '';

            [$fullname, $email, $contactNumber] = $this->resolveApplicantInfo($submission);

            if (!$email) {
                Log::info("Skipping applicant {$submission->id}, email not found");
                continue;
            }

            // ✅ Send cancellation email
            Mail::to($email)->queue((new EmailApi(
                "Interview Cancellation",
                'mail-template.cancel-interview',
                [
                    'fullname'    => $fullname,
                    'date'        => $date,
                    'time'        => $timeFormatted,
                    'venue'       => $venue,
                    'position'    => $position,
                    'SalaryGrade' => $SalaryGrade,
                    'office'      => $office,
                    'ItemNo'      => $ItemNo,
                ]
            ))->onQueue('emails'));

            // ✅ Send cancellation SMS
            $this->dispatchSms(
                contactNumber: $contactNumber,
                fullname: $fullname,
                type: 'interview-cancel', // use correct type
                date: $date,
                time: $timeFormatted,
                venue: $venue,
                position: $position,
                office: $office,
                ItemNo: $ItemNo,
            );

            $count++;

            EmailLog::create([
                'email'    => $email,
                'activity' => 'Interview cancellation',
            ]);
        }

        // ✅ Mark schedule as cancelled
        $schedule->delete();

        return response()->json([
            'success' => true,
            'message' => "Interview cancellation notices sent to {$count} applicant(s).",
        ]);
    }


    // ── SEND EMAIL EXAMINATION ───────────────────────────────────────────
    public function sendEmailExamination($validated)
    {
        $date          = Carbon::parse($validated['date_exam'])->format('F d, Y');
        $timeFormatted = Carbon::parse($validated['time_exam'])->format('g:i A');
        $time          = $validated['time_exam'];
        $venue         = $validated['venue_exam'];
        $batchName     = $validated['batch_name'];
        $count         = 0;

        $schedule = SchedulesExam::create([
            'batch_name' => $batchName,
            'date_exam'  => $validated['date_exam'],
            'time_exam'  => $time,
            'venue_exam' => $venue,
        ]);

        foreach ($validated['applicants'] as $app) {

            $submission = Submission::with('nPersonalInfo')->find($app['submission_id']);
            if (!$submission) continue;

            $job = JobBatchesRsp::find($app['job_batches_rsp']);
            if (!$job) continue;

            $position    = $job->Position    ?? '';
            $office      = $job->Office      ?? '';
            $SalaryGrade = $job->SalaryGrade ?? '';
            $ItemNo      = $job->ItemNo      ?? '';

            [$fullname, $email, $contactNumber] = $this->resolveApplicantInfo($submission);

            if (!$email) {
                Log::info("Skipping applicant {$submission->id}, email not found");
                continue;
            }

            SchedulesExamApplicant::create([
                'schedule_id'   => $schedule->id,
                'submission_id' => $submission->id,
            ]);

            // ✅ Send Email
            Mail::to($email)->queue((new EmailApi(
                "Examination Invitation",
                'mail-template.examination',
                [
                    'fullname'    => $fullname,
                    'date'        => $date,
                    'time'        => $timeFormatted,
                    'venue'       => $venue,
                    'position'    => $position,
                    'SalaryGrade' => $SalaryGrade,
                    'office'      => $office,
                    'ItemNo'      => $ItemNo,
                ]
            ))->onQueue('emails'));

            // ✅ Send SMS
            $this->dispatchSms(
                contactNumber: $contactNumber,
                fullname: $fullname,
                type: 'examination',
                date: $date,
                time: $timeFormatted,
                venue: $venue,
                position: $position,
                office: $office,
                ItemNo: $ItemNo,
            );

            $count++;

            EmailLog::create([
                'email'    => $email,
                'activity' => 'Examination invitations',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Examination invitations successfully sent to {$count} applicant(s).",
        ]);
    }

// ── REUSABLE: Resolve applicant name, email, contact ────────────────
    /**
     * Returns [$fullname, $email, $contactNumber] from a submission.
     */
    private function resolveApplicantInfo(Submission $submission): array
    {
        if ($submission->nPersonalInfo_id) {
            $firstname     = $submission->nPersonalInfo->firstname;
            $lastname      = $submission->nPersonalInfo->lastname;
            $email         = $submission->nPersonalInfo->email_address    ?? null;
            $contactNumber = $submission->nPersonalInfo->cellphone_number ?? null;
        } elseif ($submission->ControlNo) {
            $employee = DB::table('xPersonalAddt')
                ->join('xPersonal', 'xPersonalAddt.ControlNo', '=', 'xPersonal.ControlNo')
                ->where('xPersonalAddt.ControlNo', $submission->ControlNo)
                ->select(
                    'xPersonal.Firstname',
                    'xPersonal.Surname',
                    'xPersonalAddt.EmailAdd',
                    'xPersonalAddt.CellphoneNo as cellphone_number'
                )
                ->first();

            if (!$employee) return ['', null, null];

            $firstname     = $employee->Firstname;
            $lastname      = $employee->Surname;
            $email         = $employee->EmailAdd;
            $contactNumber = $employee->cellphone_number ?? null;
        } else {
            return ['', null, null];
        }

        return [
            trim("$firstname $lastname"),
            $email,
            $contactNumber,
        ];
    }

// ── REUSABLE: Dispatch SMS for Interview or Examination ──────────────
    /**
     * Builds the SMS message based on type and dispatches it.
     * $type = 'interview' | 'examination'
     */
    private function dispatchSms(
        ?string $contactNumber,
        string  $fullname,
        string  $type,
        string  $date,
        string  $time,
        string  $venue,
        string  $position,
        string  $office,
        string  $ItemNo,
    ): void {
        $normalized = $this->normalizePhoneNumber($contactNumber);

        if (!$normalized) {
            Log::info("Skipping SMS for {$fullname} — no valid contact number", [
                'raw_number' => $contactNumber ?? 'null',
            ]);
            return;
        }

        $label = $type === 'interview' ? 'interview' : 'examination';

        $smsMessage = "Dear {$fullname}, you are invited for an {$label} "
            . "for {$position} (Item No. {$ItemNo}) under {$office}. "
            . "Date: {$date}, Time: {$time}, Venue: {$venue}. "
            . "Please be on time. Thank you! Check your email for details.";

        SendApplicantSms::dispatch($normalized, $smsMessage)
            ->onQueue('sms');

        Log::info("SMS dispatched for {$label}", [
            'number'   => $normalized,
            'fullname' => $fullname,
        ]);
    }

    // ── REUSABLE: Normalize phone number ────────────────────────────────
    private function normalizePhoneNumber(?string $number): ?string
    {
        if (!$number) return null;

        $number  = preg_split('/[\/,]/', $number)[0];
        $number  = trim($number);
        $cleaned = preg_replace('/\D/', '', $number);

        if (str_starts_with($cleaned, '639') && strlen($cleaned) === 12) {
            $cleaned = '0' . substr($cleaned, 2);
        }

        if (strlen($cleaned) === 11 && str_starts_with($cleaned, '09')) {
            return $cleaned;
        }

        Log::warning('Invalid phone number, skipping SMS', [
            'original' => $number,
            'cleaned'  => $cleaned,
            'length'   => strlen($cleaned),
        ]);

        return null;
    }

    // public function sendEmailInterview($validated)
    // {

    //     $date = Carbon::parse($validated['date_interview'])->format('F d, Y');
    //     $timeFormatted = Carbon::parse($validated['time_interview'])->format('g:i A');
    //     $time = $validated['time_interview']; // store this in DB

    //     $venue = $validated['venue_interview'];
    //     $batchName = $validated['batch_name'];

    //     $count = 0;

    //     /** ✅ CREATE ONE SCHEDULE ONLY */
    //     $schedule = Schedule::create([
    //         'batch_name' => $batchName,
    //         'date_interview' => $validated['date_interview'],
    //         'time_interview' => $time,
    //         'venue_interview' => $venue,
    //     ]);

    //     foreach ($validated['applicants'] as $app) {

    //         $submission = Submission::with('nPersonalInfo')->find($app['submission_id']);
    //         if (!$submission) continue;

    //         $job = JobBatchesRsp::find($app['job_batches_rsp']);
    //         if (!$job) continue;

    //         $position = $job->Position ?? '';
    //         $office = $job->Office ?? '';
    //         $SalaryGrade = $job->SalaryGrade ?? '';
    //         $ItemNo = $job->ItemNo ?? '';

    //         // Get applicant info
    //         if ($submission->nPersonalInfo_id) {
    //             $firstname = $submission->nPersonalInfo->firstname;
    //             $lastname  = $submission->nPersonalInfo->lastname;
    //             $email     = $submission->nPersonalInfo->email_address ?? null;
    //         } else if ($submission->ControlNo) {
    //             $employee = DB::table('xPersonalAddt')
    //                 ->join('xPersonal', 'xPersonalAddt.ControlNo', '=', 'xPersonal.ControlNo')
    //                 ->where('xPersonalAddt.ControlNo', $submission->ControlNo)
    //                 ->select('xPersonalAddt.*', 'xPersonal.Firstname', 'xPersonal.Surname', 'xPersonalAddt.EmailAdd','xPersonalAddt.CellphoneNo as cellphone_number')
    //                 ->first();

    //             if (!$employee) continue;

    //             $firstname = $employee->Firstname;
    //             $lastname = $employee->Surname;
    //             $email = $employee->EmailAdd;
    //         } else {
    //             continue;
    //         }

    //         $fullname = trim("$firstname $lastname");
    //         // if (!$email) continue;
    //         if (!$email) {
    //             Log::info("Skipping applicant {$submission->id}, email not found");
    //             continue;
    //         }

    //         /** link applicant to schedule */
    //         SchedulesApplicant::create([
    //             'schedule_id' => $schedule->id,
    //             'submission_id' => $submission->id,
    //         ]);

    //         /** send email */
    //         Mail::to($email)->queue((new EmailApi(
    //             "Interview Invitation",
    //             'mail-template.interview',
    //             [

    //                 'fullname' => $fullname,
    //                 'date' => $date,
    //                 'time' => $time,
    //                 'venue' => $venue,
    //                 'position' => $position,
    //                 'SalaryGrade' => $SalaryGrade,
    //                 'office' => $office,
    //                 'ItemNo' => $ItemNo,
    //             ]
    //         ))->onQueue('emails'));

    //         $count++;

    //         EmailLog::create([
    //             'email' => $email,
    //             'activity' => 'Interview invitations',
    //         ]);
    //     }

    //     return response()->json([
    //         'success' => true,
    //         'message' => "Interview invitations successfully sent to {$count} applicant(s).",
    //     ]);
    // }


    // private function normalizePhoneNumber(?string $number): ?string
    // {
    //     if (!$number) return null;

    //     // If multiple numbers separated by / or comma, take the FIRST one only
    //     $number = preg_split('/[\/,]/', $number)[0];
    //     $number = trim($number);

    //     // Remove all non-numeric characters (spaces, dashes, parentheses, +)
    //     $cleaned = preg_replace('/\D/', '', $number);

    //     // Handle +639XXXXXXXXX → 09XXXXXXXXX (international format, 12 digits)
    //     if (str_starts_with($cleaned, '639') && strlen($cleaned) === 12) {
    //         $cleaned = '0' . substr($cleaned, 2);
    //     }

    //     // Must be exactly 11 digits starting with 09
    //     if (strlen($cleaned) === 11 && str_starts_with($cleaned, '09')) {
    //         return $cleaned;
    //     }

    //     // Invalid — too short, too long, or wrong prefix
    //     Log::warning('Invalid phone number, skipping SMS', [
    //         'original' => $number,
    //         'cleaned'  => $cleaned,
    //         'length'   => strlen($cleaned),
    //     ]);

    //     return null;
    // }
    // // ── SMS ───────────────────────────────────────────────────────────
    // private function sendApplicantSms($submission): void
    // {

    //     $fullName      = trim("{$submission->nPersonalInfo->firstname;} {$submission->nPersonalInfo->lastname}");
    //     $contactNumber = $this->normalizePhoneNumber($submission->nPersonalInfo->cellphone_number ?? null); // 👈 normalize here


    //     if (!$contactNumber) {
    //         Log::info('No valid contact number for applicant, skipping SMS', [
    //             'raw_number' => $submission->nPersonalInfo->cellphone_number ?? 'null',
    //         ]);
    //         return;
    //     }

    //     $smsMessage = $isUpdate
    //         ? "Dear {$fullName}, your application for {$job->Position} (Item No. {$job->ItemNo})"
    //         . "under {$job->Office} has been updated. "
    //         . "Please check your email for full details. Thank you!"
    //         :  "Dear {$fullName}, we acknowledge receipt of your application for "
    //         . "{$job->Position} (Item No. {$job->ItemNo}) under {$job->Office}. "
    //         . "Your application is currently under review by our HRMPSB Secretariat. "
    //         . "Please check your email for full details. Thank you!";

    //     SendApplicantSms::dispatch($contactNumber, $smsMessage)
    //         ->onQueue('sms');
    // }

    // // sending schudule examination
    // public function sendEmailExamination($validated)
    // {

    //     $date = Carbon::parse($validated['date_exam'])->format('F d, Y');
    //     $timeFormatted = Carbon::parse($validated['time_exam'])->format('g:i A');
    //     $time = $validated['time_exam']; // store this in DB

    //     $venue = $validated['venue_exam'];
    //     $batchName = $validated['batch_name'];

    //     $count = 0;

    //     /** ✅ CREATE ONE SCHEDULE ONLY */
    //     $schedule = SchedulesExam::create([
    //         'batch_name' => $batchName,
    //         'date_exam' => $validated['date_exam'],
    //         'time_exam' => $time,
    //         'venue_exam' => $venue,
    //     ]);

    //     foreach ($validated['applicants'] as $app) {

    //         $submission = Submission::with('nPersonalInfo')->find($app['submission_id']);
    //         if (!$submission) continue;

    //         $job = JobBatchesRsp::find($app['job_batches_rsp']);
    //         if (!$job) continue;

    //         $position = $job->Position ?? '';
    //         $office = $job->Office ?? '';
    //         $SalaryGrade = $job->SalaryGrade ?? '';
    //         $ItemNo = $job->ItemNo ?? '';

    //         // Get applicant info
    //         if ($submission->nPersonalInfo_id) {
    //             $firstname = $submission->nPersonalInfo->firstname;
    //             $lastname  = $submission->nPersonalInfo->lastname;
    //             $email     = $submission->nPersonalInfo->email_address ?? null;
    //         } else if ($submission->ControlNo) {
    //             $employee = DB::table('xPersonalAddt')
    //                 ->join('xPersonal', 'xPersonalAddt.ControlNo', '=', 'xPersonal.ControlNo')
    //                 ->where('xPersonalAddt.ControlNo', $submission->ControlNo)
    //                 ->select('xPersonalAddt.*', 'xPersonal.Firstname', 'xPersonal.Surname', 'xPersonalAddt.EmailAdd')
    //                 ->first();

    //             if (!$employee) continue;

    //             $firstname = $employee->Firstname;
    //             $lastname = $employee->Surname;
    //             $email = $employee->EmailAdd;
    //         } else {
    //             continue;
    //         }

    //         $fullname = trim("$firstname $lastname");
    //         // if (!$email) continue;
    //         if (!$email) {
    //             Log::info("Skipping applicant {$submission->id}, email not found");
    //             continue;
    //         }

    //         /** link applicant to schedule */
    //         SchedulesExamApplicant::create([
    //             'schedule_id' => $schedule->id,
    //             'submission_id' => $submission->id,
    //         ]);

    //         /** send email */
    //         Mail::to($email)->queue((new EmailApi(
    //             "Examination Invitation",
    //             'mail-template.examination',
    //             [

    //                 'fullname' => $fullname,
    //                 'date' => $date,
    //                 'time' => $time,
    //                 'venue' => $venue,
    //                 'position' => $position,
    //                 'SalaryGrade' => $SalaryGrade,
    //                 'office' => $office,
    //                 'ItemNo' => $ItemNo,
    //             ]
    //         ))->onQueue('emails'));

    //         $count++;

    //         EmailLog::create([
    //             'email' => $email,
    //             'activity' => 'Examination invitations',
    //         ]);
    //     }

    //     return response()->json([
    //         'success' => true,
    //         'message' => "Examination invitations successfully sent to {$count} applicant(s).",
    //     ]);
    // }


    // for the unqualified applicant that send an  the qualification and remarks
    public function sendEmailApplicantBatch($validated, $request)
    {
        $jobId = $validated['job_batches_rsp_id'];

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

        $job = \App\Models\JobBatchesRsp::with('criteria:id,job_batches_rsp_id,Education,Eligibility,Training,Experience')
            ->find($jobId);

        $position       = $job->Position ?? 'the applied position';
        $office         = $job->Office   ?? 'the corresponding office';
        $education_qs   = $job->criteria->Education   ?? 'N/A';
        $eligibility_qs = $job->criteria->Eligibility ?? 'N/A';
        $training_qs    = $job->criteria->Training    ?? 'N/A';
        $experience_qs  = $job->criteria->Experience  ?? 'N/A';

        $count = 0;

        foreach ($submissions as $submission) {
            $applicant = $submission->nPersonalInfo;

            $externalApplicant = DB::table('xPersonalAddt')
                ->join('xPersonal', 'xPersonalAddt.ControlNo', '=', 'xPersonal.ControlNo')
                ->where('xPersonalAddt.ControlNo', $submission->ControlNo)
                ->select(
                    'xPersonalAddt.*',
                    'xPersonal.Firstname',
                    'xPersonal.Surname',
                    'xPersonalAddt.EmailAdd',
                    'xPersonalAddt.Rpurok',
                    'xPersonalAddt.Rstreet',
                    'xPersonalAddt.Rbarangay',
                    'xPersonalAddt.Rcity',
                    'xPersonalAddt.Rprovince',
                    'xPersonalAddt.CellphoneNo as cellphone_number'
                )
                ->first();

            $activeApplicant = $applicant ?? $externalApplicant;

            if (!$activeApplicant) {
                Log::warning("⚠️ No applicant record found for submission ID: {$submission->id}");
                continue;
            }

            $email    = $applicant->email_address ?? $externalApplicant->EmailAdd ?? null;
            $fullname = $applicant
                ? trim("{$applicant->firstname} {$applicant->lastname}")
                : trim("{$externalApplicant->Firstname} {$externalApplicant->Surname}");

            // ✅ Get contact number from either source
            $contactNumber = $applicant
                ? ($applicant->cellphone_number          ?? null)
                : ($externalApplicant->cellphone_number  ?? null);

            if (empty($email)) {
                Log::warning("⚠️ Applicant {$fullname} has no email address.");
                continue;
            }

            $isInternal = !is_null($submission->nPersonalInfo_id);

            if ($isInternal) {
                $educationRecords   = $submission->getEducationRecordsInternal();
                $experienceRecords  = $submission->getExperienceRecordsInternal();
                $trainingRecords    = $submission->getTrainingRecordsInternal();
                $eligibilityRecords = $submission->getEligibilityRecordsInternal();

                $educationText   = $this->formatEducationForEmailInternal($educationRecords);
                $experienceText  = $this->formatExperienceForEmailInternal($experienceRecords);
                $trainingText    = $this->formatTrainingForEmailInternal($trainingRecords);
                $eligibilityText = $this->formatEligibilityForEmailInternal($eligibilityRecords);
            } else {
                $educationRecords   = $submission->getEducationRecordsExternal();
                $experienceRecords  = $submission->getExperienceRecordsExternal();
                $trainingRecords    = $submission->getTrainingRecordsExternal();
                $eligibilityRecords = $submission->getEligibilityRecordsExternal();

                $educationText   = $this->formatEducationForEmailExternal($educationRecords);
                $experienceText  = $this->formatExperienceForEmailExternal($experienceRecords);
                $trainingText    = $this->formatTrainingForEmailExternal($trainingRecords);
                $eligibilityText = $this->formatEligibilityForEmailExternal($eligibilityRecords);
            }

            try {
                // ✅ Send Email
                Mail::to($email)->queue((new EmailApi(
                    "Application - Unqualified",
                    'mail-template.unqualified',
                    [
                        'fullname'  => $fullname,
                        'lastname'  => $applicant->lastname ?? $externalApplicant->Surname ?? '',
                        'Rpurok'    => $applicant->Rpurok ?? $externalApplicant->Rpurok ?? '',
                        'street'    => $applicant->residential_street   ?? $externalApplicant->Rstreet   ?? '',
                        'barangay'  => $applicant->residential_barangay ?? $externalApplicant->Rbarangay ?? '',
                        'city'      => $applicant->residential_city     ?? $externalApplicant->Rcity     ?? '',
                        'province'  => $applicant->residential_province ?? $externalApplicant->Rprovince ?? '',
                        'position'  => $position,
                        'office'    => $office,

                        'education_qualification'  => $educationText,
                        'experience_qualification' => $experienceText,
                        'training_qualification'   => $trainingText,
                        'eligibility_qualification' => $eligibilityText,

                        'education_remark'   => $submission->education_remark   ?? 'N/A',
                        'experience_remark'  => $submission->experience_remark  ?? 'N/A',
                        'training_remark'    => $submission->training_remark    ?? 'N/A',
                        'eligibility_remark' => $submission->eligibility_remark ?? 'N/A',

                        'education_qs'   => $education_qs,
                        'eligibility_qs' => $eligibility_qs,
                        'training_qs'    => $training_qs,
                        'experience_qs'  => $experience_qs,

                        'date' => now()->format('F d, Y'),
                    ]
                ))->onQueue('emails'));

                // ✅ Send SMS — unqualified notification
                $this->dispatchSmsUnqualified(
                    contactNumber: $contactNumber,
                    fullname: $fullname,
                    position: $position,
                    office: $office,
                );

                EmailLog::create([
                    'email'    => $email,
                    'activity' => 'Unqualified',
                ]);

                $user = Auth::user();
                if ($user instanceof \App\Models\User) {
                    activity('Unqualified Applicant Email Sent')
                        ->causedBy($user)
                        ->performedOn($submission)
                        ->withProperties([
                            'name'               => $user->name,
                            'username'           => $user->username,
                            'applicant_name'     => $fullname,
                            'email'              => $email,
                            'job_post_id'        => $jobId,
                            'position'           => $position,
                            'office'             => $office,
                            'date'               => now()->format('F d, Y'),
                            'ip'                 => $request->ip(),
                            'user_agent'         => $request->header('User-Agent'),
                            'education_remark'   => $submission->education_remark   ?? 'N/A',
                            'experience_remark'  => $submission->experience_remark  ?? 'N/A',
                            'training_remark'    => $submission->training_remark    ?? 'N/A',
                            'eligibility_remark' => $submission->eligibility_remark ?? 'N/A',
                        ])
                        ->log("{$user->name} sent an unqualified notification to {$fullname} for the {$position} position in {$office}.");
                }

                $count++;
            } catch (\Exception $e) {
                Log::error("❌ Failed to send email/SMS for {$fullname}: {$e->getMessage()}");
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Unqualified email notifications sent to {$count} applicant(s)."
        ]);
    }


    // public function sendEmailApplicantBatch($validated, $request)
    // {

    //     $jobId = $validated['job_batches_rsp_id'];

    //     // Get ONLY Unqualified applicants
    //     $submissions = Submission::where('job_batches_rsp_id', $jobId)
    //         ->with('nPersonalInfo')
    //         ->where('status', 'Unqualified')
    //         ->get();

    //     if ($submissions->isEmpty()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'No Unqualified applicants found for this job post.'
    //         ], 404);
    //     }

    //     // Get job details
    //     $job = \App\Models\JobBatchesRsp::with('criteria:id,job_batches_rsp_id,Education,Eligibility,Training,Experience')
    //         ->find($jobId);

    //     $position = $job->Position ?? 'the applied position';
    //     $office = $job->Office ?? 'the corresponding office';

    //     // QS of the job post
    //     $education_qs = $job->criteria->Education ?? 'N/A';
    //     $eligibility_qs = $job->criteria->Eligibility ?? 'N/A';
    //     $training_qs = $job->criteria->Training ?? 'N/A';
    //     $experience_qs = $job->criteria->Experience ?? 'N/A';

    //     $count = 0;

    //     foreach ($submissions as $submission) {
    //         $applicant = $submission->nPersonalInfo;

    //         // Check internal/external records
    //         $externalApplicant = DB::table('xPersonalAddt')
    //             ->join('xPersonal', 'xPersonalAddt.ControlNo', '=', 'xPersonal.ControlNo')
    //             ->where('xPersonalAddt.ControlNo', $submission->ControlNo)
    //             ->select('xPersonalAddt.*', 'xPersonal.Firstname', 'xPersonal.Surname', 'xPersonalAddt.EmailAdd', 'xPersonalAddt.Rpurok', 'xPersonalAddt.Rstreet', 'xPersonalAddt.Rbarangay', 'xPersonalAddt.Rcity', 'xPersonalAddt.Rprovince',  'xPersonalAddt.CellphoneNo as cellphone_number')
    //             ->first();

    //         $activeApplicant = $applicant ?? $externalApplicant;

    //         if (!$activeApplicant) {
    //             Log::warning("⚠️ No applicant record found for submission ID: {$submission->id}");
    //             continue;
    //         }

    //         // Email
    //         $email = $applicant->email_address ?? $externalApplicant->EmailAdd ?? null;

    //         // Fullname
    //         $fullname = $applicant
    //             ? trim("{$applicant->firstname} {$applicant->lastname}")
    //             : trim("{$externalApplicant->Firstname} {$externalApplicant->Surname}");

    //         if (empty($email)) {
    //             Log::warning("⚠️ Applicant {$fullname} has no email address.");
    //             continue;
    //         }
    //         $isInternal = !is_null($submission->nPersonalInfo_id);


    //         if ($isInternal) {
    //             // INTERNAL
    //             $educationRecords  = $submission->getEducationRecordsInternal();
    //             $experienceRecords = $submission->getExperienceRecordsInternal();
    //             $trainingRecords   = $submission->getTrainingRecordsInternal();
    //             $eligibilityRecords = $submission->getEligibilityRecordsInternal();

    //             $educationText  = $this->formatEducationForEmailInternal($educationRecords);
    //             $experienceText = $this->formatExperienceForEmailInternal($experienceRecords);
    //             $trainingText   = $this->formatTrainingForEmailInternal($trainingRecords);
    //             $eligibilityText = $this->formatEligibilityForEmailInternal($eligibilityRecords);
    //         } else {
    //             // EXTERNAL
    //             $educationRecords  = $submission->getEducationRecordsExternal();
    //             $experienceRecords = $submission->getExperienceRecordsExternal();
    //             $trainingRecords   = $submission->getTrainingRecordsExternal();
    //             $eligibilityRecords = $submission->getEligibilityRecordsExternal();

    //             $educationText  = $this->formatEducationForEmailExternal($educationRecords);
    //             $experienceText = $this->formatExperienceForEmailExternal($experienceRecords);
    //             $trainingText   = $this->formatTrainingForEmailExternal($trainingRecords);
    //             $eligibilityText = $this->formatEligibilityForEmailExternal($eligibilityRecords);
    //         }


    //         $template = 'mail-template.unqualified';

    //         try {
    //             Mail::to($email)->queue((
    //                 new EmailApi(
    //                     "Application - Unqualified",
    //                     $template,
    //                     [
    //                         'fullname' => $fullname,
    //                         'lastname' => $applicant->lastname ?? $externalApplicant->Surname ?? '',
    //                         'Rpurok' => $applicant->Rpurok ?? $externalApplicant->Rpurok ?? '',
    //                         'street' => $applicant->residential_street ?? $externalApplicant->Rstreet ?? '',
    //                         'barangay' => $applicant->residential_barangay ?? $externalApplicant->Rbarangay ?? '',
    //                         'city' => $applicant->residential_city ?? $externalApplicant->Rcity ?? '',
    //                         'province' => $applicant->residential_province ?? $externalApplicant->Rprovince ?? '',
    //                         'position' => $position,
    //                         'office' => $office,

    //                         // ✅ FORMATTED QUALIFICATION TEXT (matching blade variable names)
    //                         'education_qualification' => $educationText,
    //                         'experience_qualification' => $experienceText,
    //                         'training_qualification' => $trainingText,
    //                         'eligibility_qualification' => $eligibilityText,

    //                         // Remarks
    //                         'education_remark' => $submission->education_remark ?? 'N/A',
    //                         'experience_remark' => $submission->experience_remark ?? 'N/A',
    //                         'training_remark' => $submission->training_remark ?? 'N/A',
    //                         'eligibility_remark' => $submission->eligibility_remark ?? 'N/A',

    //                         // QS of job post
    //                         'education_qs' => $education_qs,
    //                         'eligibility_qs' => $eligibility_qs,
    //                         'training_qs' => $training_qs,
    //                         'experience_qs' => $experience_qs,

    //                         'date' => now()->format('F d, Y'),
    //                     ]
    //                 )
    //             )->onQueue('emails'));

    //             EmailLog::create([
    //                 'email' => $email,
    //                 'activity' => 'Unqualified',
    //             ]);

    //             // Log::info("📧 Queued UNQUALIFIED email for {$fullname} ({$email}).");

    //             $user = Auth::user();
    //             if ($user instanceof \App\Models\User) {
    //                 activity('Unqualified Applicant Email Sent')
    //                     ->causedBy($user)
    //                     ->performedOn($submission)
    //                     ->withProperties([
    //                         'name'     => $user->name,
    //                         'username'       => $user->username,
    //                         'applicant_name' => $fullname,
    //                         'email'          => $email,
    //                         'job_post_id'    => $jobId,
    //                         'position'       => $position,
    //                         'office'         => $office,
    //                         'date'           => now()->format('F d, Y'),
    //                         'ip' => $request->ip(),
    //                         'user_agent' => $request->header('User-Agent'),
    //                         'education_remark'   => $submission->education_remark ?? 'N/A',
    //                         'experience_remark'  => $submission->experience_remark ?? 'N/A',
    //                         'training_remark'    => $submission->training_remark ?? 'N/A',
    //                         'eligibility_remark' => $submission->eligibility_remark ?? 'N/A',
    //                     ])
    //                     ->log("{$user->name} sent an unqualified notification to {$fullname} for the {$position} position in {$office}.");
    //             }


    //             $count++;
    //         } catch (\Exception $e) {
    //             Log::error("❌ Failed to send email for {$fullname}: {$e->getMessage()}");
    //         }
    //     }

    //     return response()->json([
    //         'success' => true,
    //         'message' => "Unqualified email notifications sent to {$count} applicant(s)."
    //     ]);
    // }


    // ── REUSABLE: Dispatch SMS for Unqualified Applicant ─────────────────
    private function dispatchSmsUnqualified(
        ?string $contactNumber,
        string  $fullname,
        string  $position,
        string  $office,
    ): void {
        $normalized = $this->normalizePhoneNumber($contactNumber);

        if (!$normalized) {
            Log::info("Skipping SMS for {$fullname} — no valid contact number", [
                'raw_number' => $contactNumber ?? 'null',
            ]);
            return;
        }

        $smsMessage = "Dear {$fullname}, we regret to inform you that your application "
            . "for {$position} under {$office} did not meet the qualification standards. "
            . "Please check your email for the full details. Thank you!";

        SendApplicantSms::dispatch($normalized, $smsMessage)
            ->onQueue('sms');

        Log::info('SMS dispatched for unqualified applicant', [
            'number'   => $normalized,
            'fullname' => $fullname,
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


    // for the unqualified applicant that send an  the qualification and remarks
    public function sendEmailApplicantBatchQualified($validated, $request)
    {

        $jobId = $validated['job_batches_rsp_id'];

        // Get ONLY Unqualified applicants
        $submissions = Submission::where('job_batches_rsp_id', $jobId)
            ->with('nPersonalInfo')
            ->where('status', 'Qualified')
            ->get();

        if ($submissions->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No Qualified applicants found for this job post.'
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
                ->select('xPersonalAddt.*', 'xPersonal.Firstname', 'xPersonal.Surname', 'xPersonalAddt.EmailAdd', 'xPersonalAddt.Rpurok', 'xPersonalAddt.Rstreet', 'xPersonalAddt.Rbarangay', 'xPersonalAddt.Rcity', 'xPersonalAddt.Rprovince')
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
                Mail::to($email)->queue((
                    new EmailApi(
                        "Application - Unqualified",
                        $template,
                        [
                            'fullname' => $fullname,
                            'lastname' => $applicant->lastname ?? $externalApplicant->Surname ?? '',
                            'Rpurok' => $applicant->Rpurok ?? $externalApplicant->Rpurok ?? '',
                            'street' => $applicant->residential_street ?? $externalApplicant->Rstreet ?? '',
                            'barangay' => $applicant->residential_barangay ?? $externalApplicant->Rbarangay ?? '',
                            'city' => $applicant->residential_city ?? $externalApplicant->Rcity ?? '',
                            'province' => $applicant->residential_province ?? $externalApplicant->Rprovince ?? '',
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
                )->onQueue('emails'));

                EmailLog::create([
                    'email' => $email,
                    'activity' => 'Unqualified',
                ]);

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
}
