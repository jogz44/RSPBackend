<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller; // Make sure to import the base Controller
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StructureDetailController extends Controller
{

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


}
