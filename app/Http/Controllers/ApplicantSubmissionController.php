<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplicantApplicationRequest;
use Carbon\Carbon;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ApplicantApplicationService;
use App\Services\EmployeeService;



class ApplicantSubmissionController extends Controller
{


    // list of applicant
    public function listOfApplicants(Request $request)
    {
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10);

        $query = Submission::query()
            ->join('nPersonalInfo as p', 'submission.nPersonalInfo_id', '=', 'p.id')
            ->select(
                'p.id as nPersonal_id',
                'p.firstname',
                'p.lastname',
                'p.date_of_birth',
                DB::raw('COUNT(submission.id) as jobpost')
            )
            ->groupBy('p.id', 'p.firstname', 'p.lastname', 'p.date_of_birth');

        // 🔍 Global search (works across ALL pages)
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('p.firstname', 'like', "%{$search}%")
                    ->orWhere('p.lastname', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(p.firstname,' ',p.lastname) LIKE ?", ["%{$search}%"]);
            });
        }

        $schedule = $query->paginate($perPage);

         //  'batch_name',
         // 'date_interview',
         // 'time_interview',
         // 'venue_interview'

        return response()->json($schedule);
    }


    public function getApplicantDetails(Request $request) // applicant details
    {
        $validated = $request->validate([
            'firstname' => 'required|string',
            'lastname' => 'required|string',
            'date_of_birth' => 'required|date',
        ]);

        // normalize input
        $firstname = trim(strtolower($validated['firstname']));
        $lastname = trim(strtolower($validated['lastname']));
        // ensure a Y-m-d string for whereDate
        $date_of_birth = \Carbon\Carbon::parse($validated['date_of_birth'])->toDateString();

        $applicants = Submission::select('id', 'nPersonalInfo_id', 'job_batches_rsp_id','status')
            ->whereHas('nPersonalInfo', function ($query) use ($firstname, $lastname, $date_of_birth) {
                $query->whereDate('date_of_birth', $date_of_birth)
                    ->where(function ($q) use ($firstname, $lastname) {
                        // normal order: firstname = input.firstname AND lastname = input.lastname
                        $q->whereRaw('LOWER(TRIM(firstname)) = ?', [$firstname])
                            ->whereRaw('LOWER(TRIM(lastname)) = ?', [$lastname]);
                        // OR swapped order: firstname = input.lastname AND lastname = input.firstname
                        $q->orWhereRaw('(LOWER(TRIM(firstname)) = ? AND LOWER(TRIM(lastname)) = ?)', [$lastname, $firstname]);
                    });
            })
            ->with([
                'nPersonalInfo:id,firstname,lastname,date_of_birth',
                'jobPost:id,Position,Office,SalaryGrade,salaryMin,salaryMax,status'
            ])
            ->get();

        if ($applicants->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No applicant found with the provided details.',
                'input' => [
                    'firstname' => $validated['firstname'],
                    'lastname' => $validated['lastname'],
                    'date_of_birth' => $date_of_birth,
                ]
            ], 404);
        }

        return response()->json([
            'message' => 'Applicants retrieved successfully.',
            'count' => $applicants->count(),
            'data' => $applicants
        ]);
    }

    public function index()
    {
        $submission = Submission::all();

        return response()->json($submission);
    }

    public function employeeStoreApplicantApplication(Request $request,EmployeeService $employeeService) // employee applicant
    {

        // ✅ Validate request
        $validated = $request->validate([
            'ControlNo' => 'required|string',
            'job_batches_rsp_id' => 'required|exists:job_batches_rsp,id',
        ]);

        $result = $employeeService->employeeApplicant($validated);

        return $result;

    }

    // applicant application zipfile and excel file
    public function applicantStoreApplication(ApplicantApplicationRequest $request , ApplicantApplicationService $applicantApplicationService){

    $validated = $request->validated();

    $result = $applicantApplicationService->applicantApplication($validated,$request->file('excel_file'), $request->file('zip_file'));

     return $result;

    }
}
