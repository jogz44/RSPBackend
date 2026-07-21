<?php

namespace App\Services;

class ActivityLogService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }
    // Activity -> Causer -> Subject
    public function log(string $event, $causer, $subject, array $properties, string $description): void
    {
        activity($event)
            ->causedBy($causer)
            ->performedOn($subject)
            ->withProperties(array_merge($properties, [
                'ip'         => request()->ip(),
                'user_agent' => request()->header('User-Agent'),
            ]))
            ->log($description);
    }

    // ================================= ADMIN AUTH =============================== \\

    // login activity logs
    public function logLogin($user): void
    {
        $this->log(
            'Login',
            $user,
            $user,
            [
                'username' => $user->username,
                'role'     => $user->role?->name,
                'office'   => $user->office,
            ],
            "'{$user->name}' logged in successfully."
        );
    }

    // create account activity logs
    public function logRegister($createdBy, $user): void
    {
        $this->log(
            'Register Account',
            $createdBy,
            $user,
            [
                'created_by'     => $createdBy?->name,
                'new_admin_name' => $user->name,
                'username'       => $user->username,
                'position'       => $user->position,
                'active'         => $user->active,
                'role'           => $user->role_id,
            ],
            "'{$user->name}' was registered successfully by '{$createdBy?->name}'."
        );
    }

    // logout acitivty logs
    public function logUserDeleted($deletedBy, $user): void
    {
        $this->log(
            'User Management',
            $deletedBy,
            $user,
            [
                'deleted_user_id'   => $user->id,
                'deleted_user_name' => $user->name,
                'username'          => $user->username,
                'position'          => $user->position,
                'active'            => $user->active,
            ],
            "{$deletedBy->name} deleted {$user->name} account successfully."
        );
    }

    // delete acitivty logs
    public function logLogOut($user): void
    {
        $this->log(
            'Logout',
            $user,
            $user,
            [
                'username' => $user->username,
                'role'     => $user->role?->name,
                'office'   => $user->office,
            ],
            "'{$user->name}' logout successfully."
        );
    }

    // reset password acitivty logs
    public function logPasswordReset($resetBy, $user): void
    {
        $this->log(
            'User Management',
            $resetBy,
            $user,
            [
                'target_user_id'   => $user->id,
                'target_user_name' => $user->name,
                'username'         => $user->username,
            ],
            "{$resetBy->name} reset password of {$user->name} account successfully."
        );
    }

    //  update user activity logs
    public function logUserUpdated($updatedBy, $user): void
    {
        $this->log(
            'User Management',
            $updatedBy,
            $user,
            [
                'updated_user_id'   => $user->id,
                'updated_user_name' => $user->name,
                'username'          => $user->username,
                'position'          => $user->position,
                'active'            => $user->active,
                'permissions'       => $user->rspControl?->toArray(),
            ],
            "'{$updatedBy->name}' updated user '{$user->name}' successfully."
        );
    }


    // ================================= ADMIN AUTH =============================== \\


    // ================================= RATER AUTH =============================== \\


    //  rater create account activity logs
    public function logRaterCreateAccount($createdBy, $rater): void
    {
        $this->log(
            'Rater Account Created',
            $createdBy,
            $rater,
            [
                'created_by' => $createdBy?->name,
                'new_rater_name' => $rater->name,
                'username' => $rater->username,
                'position' => $rater->position,
                'office' => $rater->office,
                'role' => 'Rater',
            ],
            "'Rater {$rater->name} was Created successfully by '{$createdBy?->name}"
        );
    }

    //  rater login activity logs
    public function logRaterLogin($rater): void
    {
        $this->log(
            'Rater Login',
            $rater,
            $rater,
            [
                'rater_name' => $rater->name,
                'rater_username' => $rater->username,
                'role' => $rater->role?->role_name,
                'office' => $rater->office,
            ],
            "'{$rater->name}' rater logged in successfully."

        );
    }

    //  rater logout  activity logs
    public function logRaterLogOut($rater): void
    {
        $this->log(
            'Rater Logout',
            $rater,
            $rater,
            [
                'rater_name' => $rater->name,
                'rater_username' => $rater->username,
                'role' => $rater->role?->role_name,
                'office' => $rater->office,
            ],
            "'{$rater->name}' rater logout successfully."

        );
    }


    //  rater updating his password activity logs
    public function logRaterUpdatePassword($rater): void
    {
        $this->log(
            'Rater update password',
            $rater,
            $rater,
            [
                'rater_name' => $rater->name,
                'rater_username' => $rater->username,
                'role' => $rater->role?->role_name,
                'office' => $rater->office,
            ],
            "'Rater {$rater->name} changed their password."

        );
    }

    //  rater updating he assign task by admin user
    public function logRaterUpdateAssignTask($updatedBy, $rater, $validated, $oldData): void
    {
        $this->log(
            'Rater update password',
            $updatedBy,
            $rater,

            [
                'updated_by' => $updatedBy?->name,
                'rater_name' => $rater->name,
                'rater_username' => $rater->username,
                'old_data' => $oldData,
                'new_data' => [
                    'office' => $rater->office,
                    'active' => $rater->active,
                    'job_batches_rsp_id' => $validated['job_batches_rsp_id'] ?? $oldData['job_batches_rsp_id'],
                ],
            ],
            "'Rater {$rater->name} update assign jobpost."

        );
    }

    // ================================= RATER AUTH =============================== \\


    // ================================= PLANTILLA =============================== \\

    // plantilla position acitivty logs 
    public function logFundedItem($user, $Id, $funded, $itemNo): void
    {

        $this->log(
            'Plantilla position status',
            $user,
            $user,
            [
                'name'     => $user->name,
                'username' => $user->username,
                'funded'   => $funded,
                'item_no'  => $itemNo,
                'ID'       => $Id,
            ],
            "{$user->name} updated Funded status of ItemNo {$itemNo} to " . ($funded ? 'Funded' : 'Unfunded') . "."
        );
    }

    // employee appointment logs
    public function logEmployeeAppointment($user, $data): void
    {
        $employee = $data->first(); // could be null if collection is empty

        // ✅ Guard: nothing to log if no record found
        if (!$employee) {
            return;
        }

        $controlNo   = $employee->ControlNo   ?? 'N/A';
        $designation = $employee->Designation ?? 'N/A';
        $office      = $employee->Office      ?? 'N/A';

        $this->log(
            'Employee Appointment',
            $user,
            $employee, // ✅ pass the actual model, not null
            [
                'name'        => $user->name,
                'username'    => $user->username,
                'ControlNo'   => $controlNo,
                'Designation' => $designation,
                'Office'      => $office,
            ],
            "{$user->name} viewed appointment record of ControlNo {$controlNo} - {$designation} at {$office}."
        );
    }
    // create jobpost activity logs
    public function logCreateJobPost($user, $jobBatch): void
    {
        $this->log(
            'Creating Job post',
            $user,
            $jobBatch,
            [
                'name' => $user->name,
                'username' => $user->username,
                'job_post_id' => $jobBatch->id,
                'position' => $jobBatch->Position,
                'item_no' => $jobBatch->ItemNo,
                'page_no' => $jobBatch->PageNo,
                'salary_grade' => $jobBatch->SalaryGrade,
            ],
            "{$user->name} created a new job post for position {$jobBatch->Position}."
        );
    }

    // ================================= PLANTILLA =============================== \\

    // ================================= Job Post ================================= \\

    // delete jobpost activity logs
    public function logDeleteJobPost($user, $jobBatch): void
    {
        $this->log(
            'Delete Job Post',
            $user,
            $jobBatch, // subject is null since the model is already deleted
            [
                'name'        => $user->name,
                'username'    => $user->username,
                'deleted_job' => [
                    'id'       => $jobBatch->id,
                    'position' => $jobBatch->Position ?? null,
                    'item_no'  => $jobBatch->ItemNo   ?? null,
                    'office'   => $jobBatch->Office    ?? null,
                    'page_no'  => $jobBatch->PageNo    ?? null,
                    'status'   => $jobBatch->status    ?? null,
                ],
            ],
            "{$user->name} deleted job post ({$jobBatch->Position}) - ItemNo: {$jobBatch->ItemNo}."
        );
    }

    // edit jobpost activity logs
    public function logEditJobPost($user, $jobBatch, $jobValidated, $request, $fileName = null): void
    {
        $this->log(
            'Edit Job Post', // ✅ fixed event name
            $user,
            $jobBatch,
            [
                'name'            => $user->name     ?? null,
                'username'        => $user->username ?? null,
                'job_post_id'     => $jobBatch->id,
                'updated_fields'  => $jobValidated,
                'criteria_updated' => [  // extract from $jobValidated instead of undefined $criteriaValidated
                    'Education'   => $jobValidated['Education']   ?? null,
                    'Eligibility' => $jobValidated['Eligibility'] ?? null,
                    'Training'    => $jobValidated['Training']    ?? null,
                    'Experience'  => $jobValidated['Experience']  ?? null,
                ],
                'file_uploaded'   => $fileName ?? 'No file uploaded', // ✅ safe default
            ],
            "{$user->name} updated the job post for position {$jobBatch->Position}."
        );
    }

    // republished job post  activity log 
    public function logRepublishedJobPost($user, $jobBatch, $jobValidated, $fileName = null): void
    {
        $this->log(
            'Republished Job Post',
            $user,
            $jobBatch,
            [
                'name'             => $user->name,
                'username'         => $user->username,
                'new_job_post_id'  => $jobBatch->id,
                'old_job_post_id'  => $jobValidated['old_job_id'] ?? null,
                'republished_job'  => [
                    'id'       => $jobBatch->id,
                    'position' => $jobBatch->Position ?? null,
                    'item_no'  => $jobBatch->ItemNo   ?? null,
                    'office'   => $jobBatch->Office   ?? null,
                    'page_no'  => $jobBatch->PageNo   ?? null,
                    'status'   => $jobBatch->status   ?? null,
                ],
                'criteria' => [
                    'Education'   => $jobValidated['Education']   ?? null,
                    'Eligibility' => $jobValidated['Eligibility'] ?? null,
                    'Training'    => $jobValidated['Training']    ?? null,
                    'Experience'  => $jobValidated['Experience']  ?? null,
                ],
                'file_uploaded' => $fileName ?? 'No file uploaded',
            ],
            "{$user->name} republished the job post for position {$jobBatch->Position} - ItemNo: {$jobBatch->ItemNo}."
        );
    }

    // unoccupied activity log job post

    public function logUnoccupied($user, $jobPost, $validated, $oldStatus)
    {
        $this->log(
            'Job Post Status Update',
            $user,
            $jobPost,
            [
                'name'         => $user->name,
                'username'     => $user->username,
                'job_post_id'  => $jobPost->id,
                'position'     => $jobPost->Position ?? null,
                'item_no'      => $jobPost->ItemNo ?? null,
                'old_status'   => $oldStatus,
                'new_status'   => $validated['status'],
            ],
            "{$user->name} marked job post {$jobPost->Position} as Unoccupied." // ✅ Missing 5th argument
        );
    }

    // ================================= Job Post ================================= \\



    // ================================= Applicant  ================================= \\


    // delete applicant applied on job post 
    // delete  this submission
    public function logDeleteApplicantApplied($deletedBy, $submission)
    {
        $this->log(
            'Applicant Submission',
            $deletedBy,
            $submission,
            [
                'submission_id' => $submission->id,        // ✅ log submission data, not user account data
                'applicant_id'  => $submission->user_id,   // adjust field name to match your schema
                'status'        => $submission->status,
                'job_post_id'   => $submission->job_post_id, // adjust to match your schema
            ],
            "{$deletedBy->name} deleted submission ID {$submission->id} successfully." // ✅ accurate description
        );
    }

    // add exam score for applicant
    public function logAddExamScoreOfApplicant($causer, $examScore)
    {
        $this->log(
            'Applicant Exam Score',
            $causer,
            $examScore, // ✅ performedOn() requires a Model instance
            [
                'submission_id'    => $examScore->submission_id,
                'exam_score'       => $examScore->exam_score,
                'exam_total_score' => $examScore->exam_total_score,
                'exam_type'        => $examScore->exam_type,
                'exam_date'        => $examScore->exam_date,
                'exam_remarks'     => $examScore->exam_remarks,
            ],
            "{$causer->name} saved exam score for submission ID {$examScore->submission_id}." // ✅ clear description
        );
    }


    // update exam score for applicant
    // log update applicant exam score
    public function logUpdateExamScoreOfApplicant($causer, $examScore, $oldValues)
    {
        $this->log(
            'Applicant Exam Score',
            $causer,
            $examScore, // ✅ performedOn() requires a Model instance
            [
                'submission_id' => $examScore->submission_id,
                'old_values'    => $oldValues,       // ✅ before state
                'new_values'    => [                 // ✅ after state
                    'exam_score'       => $examScore->exam_score,
                    'exam_total_score' => $examScore->exam_total_score,
                    'exam_type'        => $examScore->exam_type,
                    'exam_date'        => $examScore->exam_date,
                    'exam_remarks'     => $examScore->exam_remarks,
                ],
            ],
            "{$causer->name} updated exam score for submission ID {$examScore->submission_id}."
        );
    }

    // delete exam score of applicant 
    // log delete applicant exam score
    public function logApplicantExamScoreDelete($deletedBy, $exam)
    {
        $this->log(
            'Applicant Exam Score',
            $deletedBy,
            $exam,                          // ✅ correct model instance
            [
                'exam_id'          => $exam->id,               // ✅ was missing
                'submission_id'    => $exam->submission_id,    // ✅ fixed from $submission->id
                'exam_score'       => $exam->exam_score,       // ✅ relevant exam fields
                'exam_total_score' => $exam->exam_total_score,
                'exam_type'        => $exam->exam_type,
                'exam_date'        => $exam->exam_date,
                'exam_remarks'     => $exam->exam_remarks,
            ],
            "{$deletedBy->name} deleted exam score ID {$exam->id} for submission ID {$exam->submission_id}." // ✅ fixed description
        );
    }

    // log cancel exam of applicant invitation
    // log cancel examination email
    public function logCancelEmailExamination($causer, $schedule, $date, $time, $venue, $count)
    {
        $this->log(
            'Examination Schedule Cancel',          // ✅ correct event name (was 'Applicant Exam Score')
            $causer,
            $schedule,                           // ✅ correct model instance (SchedulesExam)
            [
                'name'       => $causer->name,   // ✅ use $causer, not undefined $user
                'username'   => $causer->username,
                'date_exam'  => $date,           // ✅ passed explicitly, not undefined variable
                'time_exam'  => $time,
                'venue_exam' => $venue,
                'total_sent' => $count,
            ],
            "{$causer->name} cancelled examination schedule on {$date} at {$time} — {$count} applicant(s) notified." // ✅ accurate description
        );
    }

    // log update/reschedule examination email
    public function logUpdateEmailExamination(
        $causer,
        $schedule,
        $date,
        $time,
        $venue,
        $newCount,
        $oldCount,
        $dateChanged
    ) {
        // ✅ Build a clear description based on what actually happened
        $actions = [];
        if ($dateChanged)  $actions[] = "rescheduled examination to {$date} at {$time}";
        if ($newCount > 0) $actions[] = "invited {$newCount} new applicant(s)";
        if ($oldCount > 0) $actions[] = "notified {$oldCount} existing applicant(s) of reschedule";

        $actionSummary = !empty($actions)
            ? implode(', ', $actions)
            : 'updated schedule with no emails sent';

        $this->log(
            'Examination Schedule Update',           // ✅ clear event name
            $causer,
            $schedule,                               // ✅ SchedulesExam model instance
            [
                'schedule_id'    => $schedule->id,
                'batch_name'     => $schedule->batch_name,
                'date_exam'      => $date,
                'time_exam'      => $time,
                'venue_exam'     => $venue,
                'date_changed'   => $dateChanged,    // ✅ flag if schedule actually changed
                'new_invites'    => $newCount,        // ✅ how many new applicants emailed
                'old_notified'   => $oldCount,        // ✅ how many existing applicants notified
            ],
            "{$causer->name} {$actionSummary}."      // ✅ dynamic, accurate description
        );
    }

    // log send examination email
    public function logSendEmailExamination(
        $causer,
        $scheduleExam,  // ✅ SchedulesExam model — required by performedOn()
        $batchName,
        $date,
        $time,
        $venue,
        $count
    ) {
        $this->log(
            'Examination Schedule',                     // ✅ event name (was missing entirely)
            $causer,                                 // ✅ causer (was missing)
            $scheduleExam,                           // ✅ subject — performedOn() needs a Model
            [
                'name'        => $causer->name,      // ✅ use $causer, not undefined $user
                'username'    => $causer->username,
                'schedule_id' => $scheduleExam->id,  // ✅ added for traceability
                'batch_name'  => $batchName,
                'date_exam'   => $date,
                'time_exam'   => $time,
                'venue_exam'  => $venue,
                'total_sent'  => $count,
            ],
            "{$causer->name} sent examination invitations for batch '{$batchName}' on {$date} at {$time} to {$count} applicant(s)." // ✅ description (was missing)
        );
    }

    // log send Interview email
    public function logSendEmailInterview(
        $causer,        // ✅ renamed from $user to $causer for consistency
        $schedule,      // ✅ renamed from $scheduleExam — this is a Schedule (Interview) model
        $batchName,
        $date,
        $time,
        $venue,
        $count
    ) {
        $this->log(
            'Interview Schedule',                       // ✅ correct event name (was 'Interview Schedule')
            $causer,                                 // ✅ defined parameter, not undefined $causer
            $schedule,                               // ✅ correct Schedule model instance
            [
                'name'             => $causer->name,     // ✅ $causer is now defined
                'username'         => $causer->username,
                'schedule_id'      => $schedule->id,     // ✅ $schedule is now defined
                'batch_name'       => $batchName,
                'date_interview'   => $date,             // ✅ fixed from date_exam
                'time_interview'   => $time,             // ✅ fixed from time_exam
                'venue_interview'  => $venue,            // ✅ fixed from venue_exam
                'total_sent'       => $count,
            ],
            "{$causer->name} sent interview invitations for batch '{$batchName}' on {$date} at {$time} to {$count} applicant(s)." // ✅ fixed from "examination invitations"
        );
    }


    // log update/reschedule Interview Schedule
    public function logUpdateEmailInterview(
        $causer,
        $schedule,       // ✅ Schedule (Interview) model instance
        $date,
        $time,
        $venue,
        $newCount,
        $oldCount,
        $dateChanged
    ) {
        // ✅ Build a clear description based on what actually happened
        $actions = [];
        if ($dateChanged)  $actions[] = "rescheduled interview to {$date} at {$time}";
        if ($newCount > 0) $actions[] = "invited {$newCount} new applicant(s)";
        if ($oldCount > 0) $actions[] = "notified {$oldCount} existing applicant(s) of reschedule";

        $actionSummary = !empty($actions)
            ? implode(', ', $actions)
            : 'updated schedule with no emails sent';

        $this->log(
            'Interview Schedule Update',                 // correct event name
            $causer,
            $schedule,                                   // Schedule model — performedOn()
            [
                'schedule_id'      => $schedule->id,
                'batch_name'       => $schedule->batch_name,
                'date_interview'   => $date,             // interview field names
                'time_interview'   => $time,
                'venue_interview'  => $venue,
                'date_changed'     => $dateChanged,      // flag if schedule actually changed
                'new_invites'      => $newCount,          // new applicants emailed
                'old_notified'     => $oldCount,          // existing applicants notified
            ],
            "{$causer->name} {$actionSummary}."          // dynamic, accurate description
        );
    }


    // ================================= Applicant  ================================= \\

}
