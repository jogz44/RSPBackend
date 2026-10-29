<?php

namespace App\Http\Controllers;

use App\Models\JobBatchesRsp;
use App\Models\Submission;
use App\Models\TempRegAppointmentReorg;
use App\Models\TempRegHistory;
use App\Models\xPersonal;
use App\Models\xService;
use App\Services\AppiontmentService;
use App\Services\ApplicantHiringService;
use App\Services\EmployeeService;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpParser\Node\Expr\Empty_;

class AppointmentController extends Controller
{
    use ApiResponseTrait;

    protected $appiontmentService;
    protected $hiringService;

    public function __construct(ApplicantHiringService $hiringService, AppiontmentService $appiontmentService)
    {
        $this->hiringService = $hiringService;
        $this->appiontmentService = $appiontmentService;
    }

    public function hireApplicant(Request $request)
    {   
    

        // Call the service method
        return $this->hiringService->hireApplicant($request);
    }

    public function hireTemporaryApplicant($submissionId, Request $request)
    {

        return $this->hiringService->hireTemporaryApplicant($submissionId, $request);
    }


    public function hireTemporaryRevert(int $submissionId)
    {
        $this->hiringService->hireTemporaryRevert($submissionId);

        return response()->json([
            'success' => true,
            'message' => 'Temporary hire reverted.',
        ]);
    }


    public function listOfTemporaryApplicant()
    {
        $data =  $this->hiringService->listOfTemporaryApplicantHired();

        return response()->json([
            'success' => true,
            'message' => 'list of hired temporary',
            'applicant' => $data
        ]);
    }



    public function rollbackHire($submissionId, Request $request)
    {

        // Call the service method

        return $this->hiringService->rollbackHire($submissionId, $request);
    }

    public function appiontment(Request $request)
    {

        return $this->appiontmentService->appiontment($request);
    }



    public function findAppointment()
    {
        $data = DB::table('vwplantillaStructure')
            ->where(function ($query) {
                $query->whereNull('ControlNo')
                    ->orWhere('ControlNo', '');
            })
            ->where('office', 'OFFICE OF THE CITY ACCOUNTANT') // ✅ filter by office
            ->get();

        return response()->json([
            'status' => 200,
            'data' => $data
        ]);
    }


    public function maxControlNo()
    {

        $data = DB::table('xPersonal')->max('ControlNo');
        return response()->json([
            'status' => 200,
            'data' => $data
        ]);
    }


    public function jobPost()
    {

        $data = DB::table('tblStructureDetails')->limit(5)->get();
        return response()->json([
            'status' => 200,
            'data' => $data
        ]);
    }


    public function deleteControlNo($ControlNo)
    {
        // Example: check service record
        $hasDependencies = DB::table('xPersonal')
            ->where('ControlNo', $ControlNo)
            ->exists();

        if ($hasDependencies) {
            return response()->json([
                'status' => 400,
                'message' => 'Cannot delete, employee has service records.'
            ]);
        }

        $deleted = DB::table('xPersonal')->where('ControlNo', $ControlNo)->delete();

        return response()->json([
            'status' => 200,
            'deleted' => $deleted
        ]);
    }


    public function position()
    {

        $status = DB::table('yDesignation')->get();
        return response()->json($status);
    }


    // need to fix to pagination and search optimize make readable
    public function employee(Request $request, EmployeeService $employeeService)
    {
        $result = $employeeService->listOfEmployee($request);

        return response()->json($result);
    }



    public function getEmployeePreviousDesignation($position, $status)
    {
        $today = now()->toDateString();

        $employee = DB::table(DB::raw("(SELECT
            ControlNo,
            FromDate,
            ToDate,
            Designation,
            Status,
            SepDate,
            Sepcause,
            ROW_NUMBER() OVER (
                PARTITION BY ControlNo
                ORDER BY FromDate DESC
            ) AS rn
        FROM vwpartitionforseparated
        WHERE Designation = '$position'
          AND Status = '$status'
    ) AS t"))
            ->join('xPersonal as p', 'p.ControlNo', '=', 't.ControlNo')
            ->where('t.rn', 1)
            ->whereDate('t.ToDate', '<', $today) // inactive employees only
            ->select(
                't.ControlNo',
                'p.Surname',
                'p.Firstname',
                'p.Middlename',
                't.FromDate',
                't.ToDate',
                't.Designation',
                't.Status',
                't.SepDate',
                't.SepCause'
            )
            ->get();

        return response()->json($employee);
    }

    // list of employee advance print appiotment
    public function appointmentListAdvance()
    {
        try {
            $data = $this->appiontmentService->listOfEmployeeAdvance();

            return $this->successMessage($data, 'Successfully retrieved', 200);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve appointment list advance', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return $this->errorMessage('Failed to retrieve appointment list', 500);
        }
    }

    public function effectiveDateList()
    {
        $date = xService::select('effectiveDate')->whereNotNull('effectiveDate')->where('effectiveDate', '!=', '')
            ->distinct()->orderBy('effectiveDate', 'asc')->get();
        if ($date->isEmpty()) {
            return $this->infoMessage('No effective dates found', 200);
        }
        return $this->successMessage($date, 'Successful', 200);
    }

    public function deliberationDateList()
    {
        $date = TempRegAppointmentReorg::select('deliberation_date')->whereNotNull('deliberation_date')->where('deliberation_date', '!=', '')
            ->distinct()->orderBy('deliberation_date', 'asc')->get();
        if ($date->isEmpty()) {
            return $this->infoMessage('No deliberation dates found', 200);
        }
        return $this->successMessage($date, 'Successful', 200);
    }


    public function temporaryAppointment(string $controlNo)
    {
        return $this->appiontmentService->temporaryAppointment($controlNo);
    }
}
