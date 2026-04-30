<?php

namespace App\Http\Controllers;

use App\Http\Requests\RatersRegisterRequest;
use App\Http\Requests\RaterUpdateRequest;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\RaterService;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\Validator;

class RaterAuthController extends Controller
{

    protected  $activityLogService;
    protected  $raterService;

    public function __construct(ActivityLogService $activityLogService,RaterService $raterService)
    {
        $this->activityLogService = $activityLogService;
        $this->raterService = $raterService;

    }


    //create account and register rater account
    public function createRaterAccount(RatersRegisterRequest $request)
    {
        $validated = $request->validated();

        $result = $this->raterService->create($validated,$request);

        return $result;
    }

    // updating account rater
    public function updateRater(RaterUpdateRequest $request, $id)
    {
        $validated = $request->validated();

        $result =  $this->raterService->update($validated,$id,$request);

        return $result;
    }


    // login function for rater
    public function loginRater(Request $request)
    {
        $result = $this->raterService->login($request);

        return $result;
    }


    // change password for the rater
    public function updateRaterPassword(Request $request)
    {

        // Validate request
        $validated = Validator::make($request->all(), [
            'old_password' => 'required',
            'new_password' => 'required|min:8|confirmed',
        ]);

        $result =  $this->raterService->updatePassword($validated,$request);

        return $result;
    }


    // Delete a user and associated rspControl data
    public function deleteUser($id)
    {
        try {
            DB::beginTransaction();

            $user = User::findOrFail($id);

            // Prevent deleting currently authenticated user
            if (Auth::id() == $id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You cannot delete your own account'
                ], 403);
            }

            // Delete associated rspControl first (if not using cascading deletes)
            if ($user->rspControl) {
                $user->rspControl->delete();
            }

            // Delete the user
            $user->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Rater and associated permissions deleted successfully'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Rater not found'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete Rater',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // this is logout  function for rater
    public function logoutRater(Request $request)
    {
        $user = Auth::user();

        if ($user) {
            $user->tokens()->delete();
        }

        // activity logs
        $this->activityLogService->logRaterLogOut($user);

        return response([
            'status' => true,
            'message' => 'Logout Successfully',
        ]);


    }

     // change password rater account if first time login
    public function changeRaterPassword(Request $request, RaterService $raterService)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'new_password' => 'required|min:8|confirmed',
            'new_password_confirmation' => 'required'
        ]);

     $result = $raterService->changePassword($validator,$request);

     return $result;
    }
}
