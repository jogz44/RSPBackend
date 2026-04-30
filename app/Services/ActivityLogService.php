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


    //  rater login activity logs
    public function logRaterLogin($user): void
    {
        $this->log(
            'Rater Login',
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









    // ================================= RATER AUTH =============================== \\

}
