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
            "'Rater {$rater->name} changed their password."

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

    // ================================= Job Post ================================= \\
}
