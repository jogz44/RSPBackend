<?php

namespace App\Services;

use App\Mail\EmailApi;
use App\Models\JobBatchesRsp;
use App\Models\Submission;
use App\Models\TempRegAppointmentReorg;
use App\Models\TempRegHistory;
use App\Models\vwActive;
use App\Models\vwplantillastructure;
use App\Models\xPersonal;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;


class AppiontmentService

{

    use ApiResponseTrait;

    public function appiontment(Request $request)
    {

        $user = Auth::user(); // Authenticated user
        // ✅ Step 1: Validate all inputs
        $validated = $request->validate([
            'Office' => 'required|string',
            'Office2' => 'nullable|string',
            'Group' => 'nullable|string',
            'Division' => 'nullable|string',
            'Section' => 'nullable|string',
            'Unit' => 'nullable|string',
            'Position' => 'required|string',
            'PositionID' => 'required|integer',
            'isOpen' => 'boolean',
            'PageNo' => 'required|string',
            'ItemNo' => 'required|string',
            'SalaryGrade' => 'required|string',
            'salaryMin' => 'nullable|string',
            'salaryMax' => 'nullable|string',
            'level' => 'required|string',
            'tblStructureDetails_ID' => 'required|string',

            // xService
            'FromDate' => 'required|date',
            'ToDate' => 'required|date',
            'Status' => 'required|string',

            'SepDate' => 'nullable|date',
            'SepCause' => 'nullable|string',



            // tempRegAppointmentReorg
            // 'sepdate' => 'nullable|date',
            // 'sepcause' => 'nullable|string',
            'vicename' => 'nullable|string',
            'vicecause' => 'nullable|string',
            'Renew' => 'required|string', // this is type not  renew

            // for identifying control number or user
            'controlNo' => 'required|string',
        ]);

        try {
            DB::beginTransaction();

            // ✅ Step 2: Convert to object (like    $job)

            $job = (object) $validated;

            // --- Step 1: Update PageNo if PageNo exists ---
            if ($request->has('PageNo') && $job->tblStructureDetails_ID && $job->ItemNo) {
                $exists = DB::table('tblStructureDetails')
                    ->where('PageNo', $job->PageNo)
                    ->where('ItemNo', $job->ItemNo)
                    ->where('ID', '<>', $job->tblStructureDetails_ID)
                    ->exists();

                if ($exists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Duplicate PageNo and ItemNo already exists in plantilla.'
                    ], 422);
                }

                DB::table('tblStructureDetails')
                    // ->where('PositionID', $jobValidated['PositionID'])
                    ->where('ID', $job->tblStructureDetails_ID)
                    ->where('ItemNo', $job->ItemNo)
                    ->update(['PageNo' => $job->PageNo]);
            }

            // ✅ Step 3: Pass values to updatePlantillaStructure
            $this->updatePlantillaStructure(
                $job,

                // $validated['controlNo'],
                // $validated['sepdate'] ?? null,
                // $validated['sepcause'] ?? null,
                // $validated['vicename'] ?? null,
                // $validated['vicecause'] ?? null,

            );

            activity('Plantilla')
                ->causedBy($user)
                ->withProperties([
                    'name' => $user->name,
                    'position' => $job->Position,
                    'office' => $job->Office,
                    'controlNo' => $job->controlNo,
                    'PageNo' => $job->PageNo,
                    'ItemNo' => $job->ItemNo
                ])
                ->log("User '{$user->name}' added an employee for position '{$job->Position}' in office '{$job->Office}'.");


            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Plantilla structure updated successfully!',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            // Log::error('ElectiveService Error: ' . $e->getMessage());
            return response()->json([
                'success' =>  false,
                'error' => 'Failed to update plantilla structure',
                'details' => $e->getMessage(),
            ], 500);
        }
    }


    private function updatePlantillaStructure($job)
    {


        $tblStructureDetails_ID = $job->tblStructureDetails_ID;
        $itemNo = $job->ItemNo;
        $pageNo = $job->PageNo;
        $controlNo = $job->controlNo;

        // // 1. Get the latest active service record

        // // 1. Get the latest active service record
        // $activeService = DB::table('xService')
        //     ->where('ControlNo', $controlNo)   // ensure active record
        //     ->orderBy('PMID', 'DESC')
        //     ->first();

        // // 2. If employee has active service, SepDate and SepCause are required
        // if ($activeService) {

        //     if (empty($job->SepDate) || empty($job->SepCause)) {
        //         throw new \Exception("SepDate and SepCause are required when re-appointing an active employee.");
        //     }

        //     DB::table('xService')
        //         ->where('PMID', $activeService->PMID)
        //         ->update([
        //             'SepDate'  => Carbon::parse($job->SepDate)->format('Y-m-d'),
        //             'SepCause' => $job->SepCause,
        //         ]);
        // }


        // Move old records to history, then delete old records
        $oldRecords = DB::table('tempRegAppointmentReorg')->where('ControlNo', $job->controlNo)->get();
        foreach ($oldRecords as $row) {
            TempRegHistory::create((array) $row);
        }
        DB::table('tempRegAppointmentReorg')->where('ControlNo', $job->controlNo)->delete();


        $designation = DB::table('yDesignation')
            ->select('Codes', 'Descriptions', 'Status')
            ->where('Descriptions', $job->Position ?? null)
            ->where('Status', $job->Status ?? null)
            ->first();


        $StartCode = DB::table('yStatus')
            ->select('Codes', 'Descriptions',)
            ->where('Descriptions', $job->Status ?? null)

            ->first();


        $office = DB::table('yOffice')
            ->where('Descriptions', $job->Office ?? null)
            ->orWhere('Codes', $job->Office ?? null)
            ->first();

        $officeCode = $office->Codes ?? '00000';

        $salary = DB::table('tblSalarySchedule')
            ->where('Grade', $job->SalaryGrade ?? null)
            ->where('Steps', 1 ?? null)  // NEED TO ADJUST IF THIS  IS ALWAYS 1 OF CAN BE CHANGE ANYTIME IF THEY WILL BE SELECT AND ELECTIVE
            ->first();

        // Convert & assign dates correctly for elective
        $fromDate = $job->FromDate ? Carbon::parse($job->FromDate)->startOfDay() : null;
        $toDate = $job->ToDate ? Carbon::parse($job->ToDate)->endOfDay() : null;


        $rateMon  = $salary->Salary ?? 0;
        $rateDay  = $rateMon > 0 ? $rateMon / 22 : 0;
        $rateYear = $rateMon * 12;

        $Division = DB::table('yDivision')
            ->where('Descriptions', $job->Division ?? null)
            ->orWhere('Codes', $job->Division ?? null)
            ->first();

        $DivCode = $Division->Codes ?? '00000';


        $Section = DB::table('ySection')
            ->where('Descriptions', $job->Section ?? null)
            ->orWhere('Codes', $job->Section ?? null)
            ->first();

        $SecCode = $Section->Codes ?? '00000';

        $Unit = DB::table('yUnit')
            ->where('Descriptions', $job->Unit ?? null)
            ->orWhere('Codes', $job->Unit ?? null)
            ->first();

        $UnitCode =    $Unit->Codes ?? '00000';

        DB::table('xService')
            ->where('ControlNo', $job->controlNo)
            ->where('ToDate', '>', $fromDate)
            ->update(['ToDate' => Carbon::parse($fromDate)->subDay()]);

        DB::table('xService')->insert([
            'ControlNo'    => $job->controlNo, // 1
            'FromDate'     => $fromDate ?? null, // 1
            'ToDate'       =>   $toDate   ?? null, // 1
            'DesigCode'    => $designation->Codes ?? '00000', // 1
            'Designation'  => $designation->Descriptions ?? $job->Position, // 1
            'StatCode'     => $StartCode->Codes ?? null, //1
            'Status'       => $job->Status ?? null, // 1
            'OffCode'      => $officeCode ?? '00000', // 1
            'Office'       => $job->Office ?? 'NONE',  // 1
            'BranCode'     => '00001',
            'Branch'       => 'LGU-TAGUM',
            'LVRemarks'    => '',
            'RateDay'      => $rateDay, // 1
            'RateMon'      => $rateMon, // 1
            'RateYear'     => $rateYear, // 1
            'SepDate'      => '',
            'SepCause'     => '',
            'AppCode'      => '0',
            'DivCode'      => $DivCode ?? null, // 1
            'Divisions'    => $job->Division ?? null, // 1
            'SecCode'      => $SecCode ?? null, // 1
            'Sections'     => $job->Section ?? null, // 1
            'PlantCode'    => $job->PlantCode ?? null, // 1
            'Renew'        => $job->Renew ?? null, // 1
            'Grades'       => $job->SalaryGrade ?? null, // 1
            'Steps'        => 1,
            'Charges'      => '',
        ]);

        $structure = DB::table('tblStructureDetails')
            ->where('ID', $job->tblStructureDetails_ID)
            ->where('PageNo', $job->PageNo)
            ->where('ItemNo', $job->ItemNo)
            ->first();

        // $nextId = DB::table('tempRegAppointmentReorg')->max('ID') + 1;

        DB::table('tempRegAppointmentReorg')->insert([
            // 'ID'            => $nextId,
            'ControlNo'     => $job->controlNo, //1
            'DesigCode'     => $designation->Codes ?? null, //1
            'NewDesignation' => $designation->Descriptions ?? $job->Position, //1
            'Designation'   => $designation->Descriptions ?? $job->Position, //1
            'SG'            => $job->SalaryGrade ?? null, //1
            'Step'          => 1,
            'Status'        => $job->Status ?? null, // ✅ fixed
            'OffCode'       => $officeCode, //1
            'NewOffice'     => $job->Office,
            'Office'        => $job->Office,
            'MRate'         => $rateMon, //1
            'Official'      => 0,
            'Renew'         => $job->Renew ?? null, //1
            'ItemNo'        => $itemNo,
            'Pages'         => $pageNo,
            'StructureID'   => $structure->StructureID ?? null,
            'DivCode' => $DivCode ?? null, // 1
            'SecCode' => $SecCode ?? null, // 1
            // 'groupcode',//
            // 'group',//
            'unitcode' =>     $UnitCode ?? null,
            'unit' => $job->Unit ?? null,
            'sepdate' => $job->sepdate ?? null,
            'sepcause' => $job->sepcause ?? null,
            'vicename' => $job->vicename ?? null,
            'vicecause' => $job->vicecause ?? null

        ]);
    }


    // list of employee advance appoitment
    public function listOfEmployeeAdvance()
    {
        $employeeActive = xPersonal::select('ControlNo', 'Surname', 'Firstname', 'MIddlename')
            ->get();

        $tempRegByControlNo = TempRegAppointmentReorg::select('ControlNo', 'Status', 'ItemNo', 'Pages')
            ->get()
            ->keyBy('ControlNo');

        $latestService = DB::table(DB::raw("(
        SELECT
            s.PMID,
            s.ControlNo,
            s.FromDate,
            s.ToDate,
            s.Designation,
            s.Office,
            s.Status,
            ROW_NUMBER() OVER (PARTITION BY s.ControlNo ORDER BY s.FromDate DESC) as rn
        FROM xService s
        INNER JOIN xPersonal v ON s.ControlNo = v.ControlNo
    ) as t"))
            ->where('rn', 1)
            ->whereIn('Status', ['CO-TERMINOUS', 'REGULAR', 'ELECTIVE'])
            ->get()
            ->keyBy('ControlNo');

        $today = Carbon::today();

        $result = $employeeActive
            ->map(function ($employee) use ($latestService, $tempRegByControlNo, $today) {
                $record = $latestService->get($employee->ControlNo);

                if (!$record) return null;

                $fromDate = $record->FromDate ? Carbon::parse($record->FromDate) : null;

                if (!$fromDate || $fromDate->lt($today)) return null;

                $toDate = $record->ToDate ? Carbon::parse($record->ToDate) : null;

                $tempReg = $tempRegByControlNo->get($employee->ControlNo);

                $employee->Designation = $record->Designation ?? null;
                $employee->Office      = $record->Office ?? null;
                $employee->FromDate    = $fromDate?->format('m/d/Y');
                $employee->ToDate      = $toDate?->format('m/d/Y');
                $employee->PMID        = $record->PMID ?? null;
                $employee->Status      = $tempReg->Status ?? null;
                $employee->ItemNo      = $tempReg->ItemNo ?? null;
                $employee->Pages      = $tempReg->Pages ?? null;

                return $employee;
            })
            ->filter()
            ->values();

        return $result;
    }


    private const SPECIAL_OFFICES = [
        'OFFICE OF THE CITY MAYOR',
        'OFFICE OF THE CITY VICE MAYOR / SANGGUNIANG PANLUNGSOD',
    ];

    private const SERVICE_COLUMNS = [
        'ControlNo',
        'FromDate',
        'ToDate',
        'Designation',
        'Status',
        'Office',
        'RateYear',
        'RateDay',
        'RateMon',
        'effectiveDate',
    ];

    private const PERSONAL_COLUMNS = [
        'ControlNo',
        'Surname',
        'TINNo',
        'Address',
        'BirthDate',
        'Firstname',
        'Middlename',
        'Sex',
    ];

    private const APPOINTMENT_COLUMNS = [
        'ID as tempId',
        'ControlNo',
        'DesigCode',
        'NewDesignation',
        'Designation',
        'SG',
        'Step',
        'Status',
        'OffCode',
        'NewOffice',
        'Office',
        'MRate',
        'ItemNo',
        'Pages',
        'DivCode',
        'SecCode',
        'Official',
        'Renew',
        'StructureID',
        'Groupcode',
        'group',
        'unitcode',
        'vicecause',
        'vicename',
        'sepdate',
        'sepcause',
        'deliberation_date',
        'assessment_date',
    ];

    private const APPOINTMENT_EXT_COLUMNS = [
        'ID as tempExtId',
        'ControlNo',
        'PresAppro',
        'PrevAppro',
        'SalAuthorized',
        'OtherComp',
        'SupPosition',
        'HSupPosition',
        'Tool',
        'Contact1',
        'Contact2',
        'Contact3',
        'Contact4',
        'Contact5',
        'Contact6',
        'ContactOthers',
        'Working1',
        'Working2',
        'WorkingOthers',
        'DescriptionSection',
        'DescriptionFunction',
        'StandardEduc',
        'StandardExp',
        'StandardTrain',
        'StandardElig',
        'Supervisor',
        'Core1',
        'Core2',
        'Core3',
        'Corelevel1',
        'Corelevel2',
        'Corelevel3',
        'Corelevel4',
        'Leader1',
        'Leader2',
        'Leader3',
        'Leader4',
        'leaderlevel1',
        'leaderlevel2',
        'leaderlevel3',
        'leaderlevel4',
        'structureid',
        'signing_date',
    ];

    public function temporaryAppointment(string $controlNo): JsonResponse
    {
        try {
            $service = DB::table('temporary_xservices')
                ->select(self::SERVICE_COLUMNS)
                ->where('ControlNo', $controlNo)
                ->orderByDesc('FromDate')
                ->orderByDesc('ToDate')
                ->first();

            if (!$service) {
                return response()->json(['message' => 'Temporary appointment not found.'], 404);
            }

            $personal = DB::table('temporary_x_personal')
                ->select(self::PERSONAL_COLUMNS)
                ->where('ControlNo', $controlNo)
                ->first();

            $postingDate = DB::table('temporary_posting_dates')
                ->select(['ControlNo', 'post_date', 'end_date'])
                ->where('ControlNo', $controlNo)
                ->first();

            $appointment = DB::table('temporary_tempreg_appointment_reorg')
                ->select(self::APPOINTMENT_COLUMNS)
                ->where('ControlNo', $controlNo)
                ->orderByDesc('ID')
                ->first();

            $appointmentExt = DB::table('temporary_tempreg_appointment_reorg_ext')
                ->select(self::APPOINTMENT_EXT_COLUMNS)
                ->where('ControlNo', $controlNo)
                ->orderByDesc('ID')
                ->first();

            if ($appointment) {
                $this->applySpecialOffice($appointment, $this->plantillaOffice2($controlNo));
                $appointment->employment_type = $this->employmentType($appointment->Status);
            }

            $officeHead = $this->findOfficeHead($appointment->Office ?? null);

            return response()->json([
                    'personal'        => $personal,
                    'service'         => $service,
                    'posting_date'    => $postingDate,
                    'temp_reg_appointments'     => $appointment,
                    'temp_reg_appointment_reorg_ext' => $appointmentExt,
             
                'office_head' => $officeHead,
            ]);
        } catch (\Throwable $e) {
            Log::error('temporaryAppointment failed', [
                'controlNo' => $controlNo,
                'error'     => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to fetch data'], 500);
        }
    }

    /**
     * Mayor / Vice Mayor offices use "office2" from the plantilla when it is set.
     */
    private function applySpecialOffice(object $appointment, ?string $plantillaOffice2): void
    {
        $office = trim(strtoupper($appointment->Office ?? ''));

        if (in_array($office, self::SPECIAL_OFFICES, true)) {
            $appointment->NewOffice = !empty($plantillaOffice2)
                ? $plantillaOffice2
                : $appointment->Office;
        }
    }

    /**
     * TODO: fill in. The original read this from the "plantilla" relation, which is
     * not defined anywhere in the posted code, so I can't tell which table it uses.
     */
    private function plantillaOffice2(string $controlNo): ?string
    {
        return null;
    }

    private function findOfficeHead(?string $office)
    {
        if (!$office) {
            return null;
        }

        return vwplantillastructure::select('ControlNo', 'Name4', 'office', 'position')
            ->where('office', $office)
            ->where('position', 'LIKE', 'CITY GOVERNMENT DEPARTMENT HEAD I%')
            ->first();
    }

    private function employmentType(?string $status): ?string
    {
        // Strip everything that is not a letter (tildes, dashes, spaces).
        $status = preg_replace('/[^A-Z]/', '', strtoupper(trim($status ?? '')));

        return match ($status) {
            'REGULAR'  => 'PERMANENT',
            'ELECTIVE' => 'TEMPORARY',
            default    => null,
        };
    }
}
