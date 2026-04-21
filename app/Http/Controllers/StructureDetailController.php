<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller; // Make sure to import the base Controller
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StructureDetailController extends Controller
{
    // old function working auto update no need verify
    // updating
    public function updateFunded(Request $request)
    {
        // Validate the incoming request data
        $validator = Validator::make($request->all(), [
            'ID' => 'required|string',
            'Funded'     => 'required|boolean',
            'ItemNo'     => 'required|string', // Added ItemNo validation
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $Id = $request->input('ID');
        $funded = $request->input('Funded');
        $itemNo = $request->input('ItemNo'); // Get ItemNo from request

        try {
            $updatedCount = DB::table('tblStructureDetails')
                ->where('ID', $Id)
                ->where('ItemNo', $itemNo) // Added ItemNo to the where clause
                ->update(['Funded' => $funded]);

            if ($updatedCount > 0) {

                //  Activity log BEFORE return
                $user = Auth::user();

                if ($user instanceof \App\Models\User) {
                    activity('Position status')
                        ->causedBy($user)
                        ->withProperties([
                            'name'       => $user->name,
                            'username'   => $user->username,
                            'funded'     => $funded,
                            'item_no'    => $itemNo,
                            'ID'         => $Id,
                            'ip'         => request()->ip(),
                            'user_agent' => request()->header('User-Agent'),
                        ])
                        ->log("{$user->name} updated Funded status of ItemNo {$itemNo} to " . ($funded ? 'Funded' : 'Unfunded') . ".");
                }
                return response()->json(['message' => 'Funded status updated successfully!'], 200);
            } else {
                return response()->json(['message' => 'Record not found for the given PositionID and ItemNo, or no changes were made to Funded status.'], 404);
            }
        } catch (\Exception $e) {
            // Log::error('Error updating Funded status: ' . $e->getMessage());
            return response()->json(['message' => 'An error occurred while updating the Funded status.'], 500);
        }


    }

    // // STEP 1 — User requests to fund/unfund (does NOT update yet)
    // public function requestFunded(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'ID'     => 'required|string',
    //         'Funded' => 'required|boolean',
    //         'ItemNo' => 'required|string',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(['errors' => $validator->errors()], 422);
    //     }

    //     $user = Auth::user();

    //     // Check if there's already a pending request for this item
    //     $existing = DB::table('funded_requests')
    //         ->where('tblStructureDetails_Id', $request->ID)
    //         ->where('ItemNo', $request->ItemNo)
    //         ->where('status', 'pending')
    //         ->first();

    //     if ($existing) {
    //         return response()->json([
    //             'message' => 'There is already a pending request for this item.',
    //         ], 422);
    //     }

    //     // Create the request — does NOT update tblStructureDetails yet
    //     DB::table('funded_requests')->insert([
    //         'tblStructureDetails_Id'           => $request->ID,
    //         'ItemNo'       => $request->ItemNo,
    //         'Funded'       => $request->Funded,
    //         'status'       => 'pending',
    //         'requested_by' => $user->id,
    //         'created_at'   => now(),
    //         'updated_at'   => now(),
    //     ]);

    //     // Activity log
    //     if ($user instanceof \App\Models\User) {
    //         activity('Position Status')
    //             ->causedBy($user)
    //             ->withProperties([
    //                 'name'     => $user->name,
    //                 'username' => $user->username,
    //                 'funded'   => $request->Funded,
    //                 'item_no'  => $request->ItemNo,
    //             'tblStructureDetails_Id'       => $request->ID,
    //                 'ip'       => request()->ip(),
    //             ])
    //             ->log("{$user->name} requested to " . ($request->Funded ? 'Fund' : 'Unfund') . " ItemNo {$request->ItemNo}. Waiting for approval.");
    //     }

    //     return response()->json([
    //         'message' => 'Request submitted. Waiting for admin approval.',
    //     ], 200);
    // }


    // //  STEP 2 — Admin approves or rejects the request
    // public function approveFunded(Request $request, $fundedRequestId)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'action'  => 'required|in:approved,rejected',  // approved or rejected
    //         'remarks' => 'nullable|string',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(['errors' => $validator->errors()], 422);
    //     }

    //     $fundedRequest = DB::table('funded_requests')->where('id', $fundedRequestId)->first();

    //     if (!$fundedRequest) {
    //         return response()->json(['message' => 'Request not found.'], 404);
    //     }

    //     if ($fundedRequest->status !== 'pending') {
    //         return response()->json(['message' => 'This request has already been processed.'], 422);
    //     }

    //     $user = Auth::user();

    //     // Update the request status
    //     DB::table('funded_requests')
    //         ->where('id', $fundedRequestId)
    //         ->update([
    //             'status'      => $request->action,
    //             'approved_by' => $user->id,
    //             'remarks'     => $request->remarks,
    //             'approved_at' => now(),
    //             'updated_at'  => now(),
    //         ]);

    //     // ✅ Only update tblStructureDetails if APPROVED
    //     // If APPROVED → set the actual requested Funded value (0 or 1)
    //     if ($request->action === 'approved') {
    //         DB::table('tblStructureDetails')
    //             ->where('ID', $fundedRequest->tblStructureDetails_Id)
    //             ->where('ItemNo', $fundedRequest->ItemNo)
    //             ->update(['Funded' => $fundedRequest->Funded]); // sets to 0 or 1
    //     }

    //     // If REJECTED → revert back to original value (undo the 2)
    //     if ($request->action === 'rejected') {
    //         DB::table('tblStructureDetails')
    //             ->where('ID', $fundedRequest->tblStructureDetails_Id)
    //             ->where('ItemNo', $fundedRequest->ItemNo)
    //             ->update(['Funded' => !$fundedRequest->Funded]); // revert to opposite
    //     }
    //     // Activity log
    //     if ($user instanceof \App\Models\User) {
    //         activity('Position Status')
    //             ->causedBy($user)
    //             ->withProperties([
    //                 'name'      => $user->name,
    //                 'username'  => $user->username,
    //                 'action'    => $request->action,
    //                 'item_no'   => $fundedRequest->ItemNo,
    //             'tblStructureDetails_Id'        => $fundedRequest->tblStructureDetails_Id,
    //                 'remarks'   => $request->remarks,
    //                 'ip'        => request()->ip(),
    //             ])
    //             ->log("{$user->name} {$request->action} the funded request for ItemNo {$fundedRequest->ItemNo}.");
    //     }

    //     return response()->json([
    //         'message' => "Request has been {$request->action}.",
    //     ], 200);
    // }

    // // ✅ Get all pending requests (for admin to review)
    // public function getPendingRequests()
    // {
    //     $pending = DB::table('funded_requests')
    //         ->where('status', 'pending')
    //         ->join('users', 'funded_requests.requested_by', '=', 'users.id')
    //         ->select('funded_requests.*', 'users.name as requested_by_name')
    //         ->get();

    //     if ($pending->isEmpty()) {
    //         return response()->json([
    //             'message' => 'No pending requests available.',
    //             'data'    => [],
    //         ], 200);
    //     }
    //     return response()->json([
    //         'message' => 'Pending requests retrieved successfully.',
    //         'data'    => $pending,
    //     ], 200);
    // }

}
