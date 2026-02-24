<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeUpdateCredentialsRequest;
use App\Models\xPersonal;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\table;
use App\Models\vwplantillastructure;
use App\Services\EmployeeService;
use Illuminate\Support\Facades\Auth;

class EmployeeController extends Controller
{
    //

    public function appliedEmployee($ControlNo)
    {
        // Get all submissions of employee using ControlNo
        $employeeApplications = Submission::with('jobPost')
            ->where('ControlNo', $ControlNo)
            ->get();

        return response()->json([
            'data' => $employeeApplications->map(function ($submission) {
                return [
                    'submission_id' => $submission->id,
                    'status'        => $submission->status,
                    'position'      => $submission->jobPost->Position ?? null,
                    'office'        => $submission->jobPost->Office ?? null,
                    'applied_at'    => $submission->created_at,
                ];
            })
        ]);
    }

    //update tempreg and xservice and xpersonal  of the employee
    public function updateEmployeeCredentials(EmployeeUpdateCredentialsRequest $request, $ControlNo, EmployeeService $employeeService){

        $validated = $request->validated();

        $result = $employeeService->updateCredentials($ControlNo,$validated);

        return $result;


    }

}
