<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplicantApplicationRequest;
use App\Http\Requests\applicantQsEditRequest;
use App\Http\Requests\EmployeeStoreApplicationRequest;
use App\Models\Submission;
use App\Services\ApplicantApplicationService;
use App\Services\ApplicantService;
use App\Services\EmployeeService;
use Carbon\Carbon;
use Illuminate\Http\Request; // ✅ CORRECT

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class ApplicantSubmissionController extends Controller
{


    protected $applicantApplicationService;
    protected $employeeService;
    protected $applicantService;

    public function __construct(EmployeeService $employeeService, ApplicantApplicationService $applicantApplicationService, ApplicantService $applicantService)
    {
        $this->employeeService = $employeeService;
        $this->applicantApplicationService = $applicantApplicationService;
        $this->applicantService = $applicantService;
    }



    // list of applicant applied internal - external
    public function listOfApplicants(Request $request)
    {
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10);

        // External applicants — linked to nPersonalInfo
        $external = Submission::query()
            ->join('nPersonalInfo as p', 'submission.nPersonalInfo_id', '=', 'p.id')
            ->select(
                DB::raw('MIN(p.id) as nPersonal_id'),
                'p.firstname',
                'p.lastname',
                'p.date_of_birth',
                DB::raw('COUNT(submission.id) as jobpost'),
                DB::raw("'external' as applicant_type"),
                DB::raw('NULL as ControlNo')
            )
            ->groupBy('p.firstname', 'p.lastname', 'p.date_of_birth');

        if ($search) {
            $external->where(function ($q) use ($search) {
                $q->where('p.firstname', 'like', "%{$search}%")
                    ->orWhere('p.lastname', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(p.firstname,' ',p.lastname) LIKE ?", ["%{$search}%"]);
            });
        }

        // Internal applicants — joined to xPersonal via ControlNo
        $internal = Submission::query()
            ->whereNull('submission.nPersonalInfo_id')
            ->join('xPersonal as xp', 'submission.ControlNo', '=', 'xp.ControlNo')
            ->select(
                DB::raw('NULL as nPersonal_id'),
                'xp.Firstname',
                'xp.Surname',
                'xp.BirthDate',
                DB::raw('COUNT(submission.id) as jobpost'),
                DB::raw("'internal' as applicant_type"),
                'submission.ControlNo'
            )
            ->groupBy('xp.Firstname', 'xp.Surname', 'xp.BirthDate', 'submission.ControlNo');

        if ($search) {
            $internal->where(function ($q) use ($search) {
                $q->where('xp.Firstname', 'like', "%{$search}%")
                    ->orWhere('xp.Surname', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(xp.Firstname,' ',xp.Surname) LIKE ?", ["%{$search}%"])
                    ->orWhere('submission.ControlNo', 'like', "%{$search}%");
            });
        }

        // Combine both using UNION ALL, then paginate
        $query = $external->unionAll($internal);

        $schedule = DB::table(DB::raw("({$query->toSql()}) as combined"))
            ->mergeBindings($query->getQuery())
            ->paginate($perPage);

        return response()->json($schedule);
    }

    // fetch the applicant details he applied
    public function getApplicantDetails(Request $request)
    {
        $validated = $request->validate([
            'firstname'     => 'required|string',
            'lastname'      => 'required|string',
            'date_of_birth' => 'required|date',
        ]);

        $firstname     = trim(strtolower($validated['firstname']));
        $lastname      = trim(strtolower($validated['lastname']));

        // ✅ Strip time portion — "1996-01-17 " or "1996-01-17 00:00:00" → "1996-01-17"
        $date_of_birth = \Carbon\Carbon::parse(trim($validated['date_of_birth']))->format('Y-m-d');

        // ✅ sqlsrv strips quotes from ALL ? bindings in whereRaw — embed as quoted literals instead
        $sqlDate      = "'{$date_of_birth}'";       // '1996-01-17'
        $sqlFirstname = "'{$firstname}'";            // 'alexandria'
        $sqlLastname  = "'{$lastname}'";             // 'hartmann'

        // ── External applicants (nPersonalInfo_id is NOT NULL) ──────────────────
        $external = Submission::select('id', 'nPersonalInfo_id', 'ControlNo', 'job_batches_rsp_id', 'status')
            ->whereNotNull('nPersonalInfo_id')
            ->whereHas('nPersonalInfo', function ($query) use ($sqlFirstname, $sqlLastname, $sqlDate) {
                $query
                    ->whereRaw("TRY_CAST(date_of_birth AS DATE) = TRY_CAST({$sqlDate} AS DATE)")
                    ->where(function ($q) use ($sqlFirstname, $sqlLastname) {
                        $q->where(function ($q2) use ($sqlFirstname, $sqlLastname) {
                            $q2->whereRaw("LOWER(TRIM(firstname)) = {$sqlFirstname}")
                                ->whereRaw("LOWER(TRIM(lastname))  = {$sqlLastname}");
                        })->orWhere(function ($q2) use ($sqlFirstname, $sqlLastname) {
                            $q2->whereRaw("LOWER(TRIM(firstname)) = {$sqlLastname}")
                                ->whereRaw("LOWER(TRIM(lastname))  = {$sqlFirstname}");
                        });
                    });
            })
            ->with([
                'nPersonalInfo:id,firstname,lastname,date_of_birth',
                'jobPost:id,Position,Office,SalaryGrade,salaryMin,salaryMax,status',
            ])
            ->get()
            ->map(function ($item) {
                $item->applicant_type = 'external';
                $item->personal_info  = $item->nPersonalInfo;
                return $item;
            });

        // ── Internal applicants (nPersonalInfo_id IS NULL) ───────────────────────
        $internal = Submission::select('id', 'nPersonalInfo_id', 'ControlNo', 'job_batches_rsp_id', 'status')
            ->whereNull('nPersonalInfo_id')
            ->whereHas('xPersonal', function ($query) use ($sqlFirstname, $sqlLastname, $sqlDate) {
                $query
                    ->whereRaw("TRY_CAST(BirthDate AS DATE) = TRY_CAST({$sqlDate} AS DATE)")
                    ->where(function ($q) use ($sqlFirstname, $sqlLastname) {
                        $q->where(function ($q2) use ($sqlFirstname, $sqlLastname) {
                            $q2->whereRaw("LOWER(TRIM(Firstname)) = {$sqlFirstname}")
                                ->whereRaw("LOWER(TRIM(Surname))   = {$sqlLastname}");
                        })->orWhere(function ($q2) use ($sqlFirstname, $sqlLastname) {
                            $q2->whereRaw("LOWER(TRIM(Firstname)) = {$sqlLastname}")
                                ->whereRaw("LOWER(TRIM(Surname))   = {$sqlFirstname}");
                        });
                    });
            })
            ->with([
                'xPersonal',
                'jobPost:id,Position,Office,SalaryGrade,salaryMin,salaryMax,status',
            ])
            ->get()
            ->map(function ($item) {
                $item->applicant_type  = 'internal';
                $item->personal_info   = $item->xPersonal
                    ? [
                        'firstname'     => $item->xPersonal->Firstname,
                        'lastname'      => $item->xPersonal->Surname,
                        'date_of_birth' => \Carbon\Carbon::parse($item->xPersonal->BirthDate)->format('m/d/Y'),
                    ]
                    : null;
                $item->n_personal_info = $item->personal_info;
                unset($item->xPersonal, $item->x_personal);
                return $item;
            });

        // ── Merge ────────────────────────────────────────────────────────────────
        $applicants = $external->merge($internal);

        if ($applicants->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No applicant found with the provided details.',
                'input'   => [
                    'firstname'     => $validated['firstname'],
                    'lastname'      => $validated['lastname'],
                    'date_of_birth' => $date_of_birth,
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Applicants retrieved successfully.',
            'count'   => $applicants->count(),
            'data'    => $applicants,
        ]);
    }


    // // fetch the applicant details he applied
    // public function getApplicantDetails(Request $request)
    // {
    //     $validated = $request->validate([
    //         'firstname'     => 'required|string',
    //         'lastname'      => 'required|string',
    //         'date_of_birth' => 'required|date',
    //     ]);

    //     $firstname     = trim(strtolower($validated['firstname']));
    //     $lastname      = trim(strtolower($validated['lastname']));
    //     $date_of_birth = \Carbon\Carbon::parse($validated['date_of_birth'])->toDateString();

    //     // ── External applicants (nPersonalInfo_id is NOT NULL) ──────────────────
    //     $external = Submission::select('id', 'nPersonalInfo_id', 'ControlNo', 'job_batches_rsp_id', 'status')
    //         ->whereNotNull('nPersonalInfo_id')
    //         ->whereHas('nPersonalInfo', function ($query) use ($firstname, $lastname, $date_of_birth) {
    //             $query->whereDate('date_of_birth', $date_of_birth)
    //                 ->where(function ($q) use ($firstname, $lastname) {
    //                     $q->where(function ($q2) use ($firstname, $lastname) {
    //                         $q2->whereRaw('LOWER(TRIM(firstname)) = ?', [$firstname])
    //                             ->whereRaw('LOWER(TRIM(lastname)) = ?', [$lastname]);
    //                     })->orWhere(function ($q2) use ($firstname, $lastname) {
    //                         $q2->whereRaw('LOWER(TRIM(firstname)) = ?', [$lastname])
    //                             ->whereRaw('LOWER(TRIM(lastname)) = ?', [$firstname]);
    //                     });
    //                 });
    //         })
    //         ->with([
    //             'nPersonalInfo:id,firstname,lastname,date_of_birth',
    //             'jobPost:id,Position,Office,SalaryGrade,salaryMin,salaryMax,status',
    //         ])
    //         ->get()
    //         ->map(function ($item) {
    //             $item->applicant_type = 'external';
    //             $item->personal_info  = $item->nPersonalInfo;
    //             return $item;
    //         });

    //     // ── Internal applicants (nPersonalInfo_id IS NULL, name from xPersonal) ─
    //     $internal = Submission::select('id', 'nPersonalInfo_id', 'ControlNo', 'job_batches_rsp_id', 'status')
    //         ->whereNull('nPersonalInfo_id')
    //         ->whereHas('xPersonal', function ($query) use ($firstname, $lastname, $date_of_birth) {
    //             $query->whereDate('BirthDate', $date_of_birth)
    //                 ->where(function ($q) use ($firstname, $lastname) {
    //                     $q->where(function ($q2) use ($firstname, $lastname) {
    //                         $q2->whereRaw('LOWER(TRIM(Firstname)) = ?', [$firstname])
    //                             ->whereRaw('LOWER(TRIM(Surname)) = ?', [$lastname]);
    //                     })->orWhere(function ($q2) use ($firstname, $lastname) {
    //                         $q2->whereRaw('LOWER(TRIM(Firstname)) = ?', [$lastname])
    //                             ->whereRaw('LOWER(TRIM(Surname)) = ?', [$firstname]);
    //                     });
    //                 });
    //         })
    //         ->with([
    //             'xPersonal',
    //             'jobPost:id,Position,Office,SalaryGrade,salaryMin,salaryMax,status',
    //         ])
    //         ->get()
    //         ->map(function ($item) {
    //             $item->applicant_type = 'internal';
    //             $item->personal_info  = $item->xPersonal
    //                 ? [
    //                     'firstname'     => $item->xPersonal->Firstname,
    //                     'lastname'      => $item->xPersonal->Surname,
    //                     'date_of_birth' => \Carbon\Carbon::parse($item->xPersonal->BirthDate)->format('m/d/Y'),
    //                 ]
    //                 : null;
    //             $item->n_personal_info = $item->xPersonal  // ← mirrors external's n_personal_info
    //                 ? [
    //                     'firstname'     => $item->xPersonal->Firstname,
    //                     'lastname'      => $item->xPersonal->Surname,
    //                     'date_of_birth' => \Carbon\Carbon::parse($item->xPersonal->BirthDate)->format('m/d/Y'),
    //                 ]
    //                 : null;
    //             unset($item->xPersonal);
    //             unset($item->x_personal);
    //             return $item;
    //         });

    //     // ── Merge both result sets ───────────────────────────────────────────────
    //     $applicants = $external->merge($internal);

    //     if ($applicants->isEmpty()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'No applicant found with the provided details.',
    //             'input'   => [
    //                 'firstname'     => $validated['firstname'],
    //                 'lastname'      => $validated['lastname'],
    //                 'date_of_birth' => $date_of_birth,
    //             ],
    //         ], 404);
    //     }

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Applicants retrieved successfully.',
    //         'count'   => $applicants->count(),
    //         'data'    => $applicants,
    //     ]);
    // }

    public function index()
    {
        $submission = Submission::all();

        return response()->json($submission);
    }


    // applicant application zipfile and excel file
    public function applicantStoreApplication(ApplicantApplicationRequest $request,)
    {

        $validated = $request->validated();

        $result = $this->applicantApplicationService->applicantApplication($validated, $request->file('excel_file'), $request->file('zip_file'));

        return $result;
    }

    // applicant application zipfile and excel file
    public function applicantStoreApplicationManual(Request $request)
    {

        $validated = $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,xls,csv,xlsm',
            'zip_file' => 'required|file|mimes:zip',
            'job_batches_rsp_id' => 'required|exists:job_batches_rsp,id',

        ]);

        $result = $this->applicantApplicationService->applicantApplicationManual($validated, $request->file('excel_file'), $request->file('zip_file'));

        return $result;
    }

    // applicant application zipfile and excel file
    public function updatingApplicantApplication(Request $request)
    {

        $validated = $request->validate([
            'confirmation_token' => 'required|string',
            'confirm_update' => 'required|boolean',
        ]);

        $result = $this->applicantApplicationService->confirmDuplicateApplicant($validated);

        return $result;
    }

    // internal employee applicant application image
    // public function employeeStoreApplicantApplication(EmployeeStoreApplicationRequest $request)
    // {
    //     $validated = $request->validated();

    //     $images = $request->file('images') ?? []; // ['education' => [...], 'training' => [...]]

    //     $result = $this->employeeService->employeeApplicant($validated, $images);

    //     return $result;
    // }

    public function employeeStoreApplicantApplication(EmployeeStoreApplicationRequest $request)
    {
        //  Get PHP limits
        $maxFileUploads = (int) ini_get('max_file_uploads');

        //  Count actual files received by PHP
        $receivedFiles = 0;
        foreach ($request->allFiles()['images'] ?? [] as $category => $files) {
            $receivedFiles += count((array) $files);
        }

        // Throw error if files were silently dropped
        // This happens when PHP drops files before Laravel sees them
        $expectedFiles = 0;
        foreach ($request->input('images', []) as $category => $files) {
            $expectedFiles += count((array) $files);
        }
        if ($receivedFiles < $expectedFiles || $receivedFiles >= $maxFileUploads) {
            $errorMessage = "File upload limit reached. PHP only allows {$maxFileUploads} files per request. You sent {$receivedFiles} files (some may have been dropped). Please split your uploads into smaller batches of " . ($maxFileUploads - 1) . " files or less.";

            return response()->json([
                'message' => $errorMessage,
                'errors' => [
                    'file' => [$errorMessage]
                ]
            ], 422);
        }

        $validated = $request->validated();
        $images    = $request->file('images') ?? [];
        $result    = $this->employeeService->employeeApplicant($validated, $images);

        return $result;
    }

    // applicant photo
    public function applicantPhoto($nPersonalInfoId)
    {

        $result = $this->applicantService->getApplicantPhoto($nPersonalInfoId);

        return $result;
    }

    // edit qs of applicant
    public function applicantQsEdit(applicantQsEditRequest $request)
    {

        $validated = $request->validated();

        $result = $this->applicantService->qsEdit($validated);


        return $result;
    }
}
