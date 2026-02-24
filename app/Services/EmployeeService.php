<?php

namespace App\Services;

use App\Mail\EmailApi;
use App\Models\JobBatchesRsp;
use App\Models\Submission;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class EmployeeService
{
    /**
     * Create a new class instance.
     */
    // public function __construct()
    // {
    //     //
    // }


    // need to fix to pagination and search optimize make readable
    public function listOfEmployee($request)
    {
        $search  = $request->input('search');
        $perPage = $request->input('per_page', 10);

        $query = DB::table('xPersonal')
            ->select('ControlNo', 'Firstname', 'Surname', 'Occupation');

        // 🔍 Search filter
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('ControlNo', 'like', "%{$search}%")
                    ->orWhere('Firstname', 'like', "%{$search}%")
                    ->orWhere('Surname', 'like', "%{$search}%")
                    ->orWhere('Occupation', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(Firstname,' ',Surname) LIKE ?", ["%{$search}%"]);
            });
        }

        $employees = $query->paginate($perPage);

        return $employees;
    }


    // employee applying  on the job post  storing his application on submission table
    public function employeeApplicant($validated) // employee applicant
    {

        $controlNo = $validated['ControlNo'];
        $jobId     = $validated['job_batches_rsp_id'];

        // ✅ Fetch applicant info
        $applicant = DB::table('xPersonal')
            ->join('xPersonalAddt', 'xPersonalAddt.ControlNo', '=', 'xPersonal.ControlNo')
            ->select(
                'xPersonal.Firstname',
                'xPersonal.Surname',
                'xPersonal.BirthDate',
                'xPersonalAddt.EmailAdd'
            )
            ->where('xPersonal.ControlNo', $controlNo)
            ->first();

        if (!$applicant) {
            return response()->json([
                'message' => 'Applicant not found.'
            ], 404);
        }

        // Convenience variables
        $firstname = $applicant->Firstname;
        $lastname  = $applicant->Surname;
        $birthdate = $applicant->BirthDate;

        $currentJob = JobBatchesRsp::findOrFail($jobId);


        // 2) GET ALL JOB POSTS WITH SAME START/END DATE

        $jobGroupIds = JobBatchesRsp::where('post_date', $currentJob->post_date)
            ->where('end_date', $currentJob->end_date)
            ->pluck('id');


        // 3) COUNT HOW MANY JOBS THE APPLICANT APPLIED WITHIN GROUP

        $applicationCount = DB::table('submission')
            ->join('xPersonal', 'xPersonal.ControlNo', '=', 'submission.ControlNo')
            ->whereIn('submission.job_batches_rsp_id', $jobGroupIds)
            ->where('xPersonal.Firstname', $firstname)
            ->where('xPersonal.Surname', $lastname)
            ->where('xPersonal.BirthDate', $birthdate)
            ->count();

        $post_date = Carbon::parse($currentJob->post_date)->format('F d, Y');
        $end_date   = Carbon::parse($currentJob->end_date)->format('F d, Y');


        if ($applicationCount >= 3) {
            return response()->json([
                'success' => false,
                'message' => "$firstname $lastname, You have already applied for 3 job posts with the same application period (" .
                    $post_date . " to " . $end_date . ")."
            ], 422);
        }

        //  CHECK IF APPLICANT ALREADY APPLIED TO THIS JOB
        $existing = DB::table('submission')
            ->join('xPersonal', 'xPersonal.ControlNo', '=', 'submission.ControlNo')
            ->where('submission.job_batches_rsp_id', $jobId)
            ->where('xPersonal.Firstname', $firstname)
            ->where('xPersonal.Surname', $lastname)
            ->where('xPersonal.BirthDate', $birthdate)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => "$firstname $lastname, you have already applied for this job.",
                'submission_id' => $existing->id ?? null
            ], 409);
        }

        //  CREATE SUBMISSION (NO DUPLICATE FOUND)
        $submit = Submission::create([
            'ControlNo' => $controlNo,
            'status' => 'pending',
            'job_batches_rsp_id' => $jobId,
            'nPersonalInfo_id' => null,
        ]);

        //  SEND EMAIL USING YOUR PRIVATE FUNCTION
        if (!empty($applicant->EmailAdd)) {
            // Format data for private function
            $emailApplicant = (object) [
                'email_address' => $applicant->EmailAdd,
                'firstname'     => $firstname,
                'lastname'      => $lastname
            ];

            $this->sendApplicantEmail($emailApplicant, $jobId, false);
        }

        return response()->json([
            'success' => true,
            'message' => 'Submission created successfully and email sent.',
            'data' => $submit
        ], 201);
    }


    // /**
    //  * Send applicant confirmation or update email.
    //  */
    private function sendApplicantEmail($applicant, $jobId, $isUpdate)
    {
        $job = \App\Models\JobBatchesRsp::findOrFail($jobId);

        $subject = $isUpdate ? 'Application Updated' : 'Application Received';

        $template = 'mail-template.application';


        Mail::to($applicant->email_address)->queue(new EmailApi(
            $subject,
            $template,
            [
                'mailSubject' => $subject,
                'firstname' => $applicant->firstname,
                'lastname' => $applicant->lastname,
                'jobOffice' => $job->Office,
                'jobPosition' => $job->Position,
                'isUpdate' => $isUpdate,
            ]


        ));
    }


    //update tempreg and xservice and xpersonal  of the employee
    public function updateCredentials($controlNo,$validated)
    {
        $user = Auth::user(); // User performing the update


        $xPersonal  = DB::table('xPersonal')
            ->where('ControlNo', $controlNo)
            ->update([
                'Surname' => $validated['Surname'] ?? null,
                'Firstname' => $validated['Firstname'] ?? null,
                'Middlename' => $validated['Middlename'] ?? null,
                'Sex' => $validated['Sex'] ?? 'N/A',
                'CivilStatus' => $validated['CivilStatus'] ?? null,
                'BirthDate' => $validated['BirthDate'] ?? null,
                'TINNo' => $validated['TINNo'] ?? null,
                'Address' => $validated['Address'] ?? null,

            ]);
            
        $updatedEmployee = DB::table('xPersonal')->where('ControlNo', $controlNo)->first();
        $employeeFullname = $updatedEmployee->Firstname . ' ' . $updatedEmployee->Surname;

        $xtempreg = DB::table('tempRegAppointmentReorg')
            ->where('ControlNo', $controlNo)
            ->orderByDesc('ID')
            ->first();

        if ($xtempreg) {
            DB::table('tempRegAppointmentReorg')
                ->where('ID', $xtempreg->ID)
                ->update([
                    'sepdate' => $validated['sepdate'] ?? null,
                    'sepcause' => $validated['sepcause'] ?? null,
                    'vicename' => $validated['vicename'] ?? null,
                    'vicecause' => $validated['vicecause'] ?? null,

                ]);
        }

        $tempregExt = DB::table('tempRegAppointmentReorgExt')
            ->where('ControlNo', $controlNo)
            ->orderByDesc('ID')
            ->first();

        $data = [
            'ControlNo' => $controlNo,
            'PresAppro'        => $validated['PresAppro'] ?? null,
            'PrevAppro'        => $validated['PrevAppro'] ?? null,
            'SalAuthorized'    => $validated['SalAuthorized'] ?? null,
            'OtherComp'        => $validated['OtherComp'] ?? null,
            'SupPosition'      => $validated['SupPosition'] ?? null,
            'HSupPosition'     => $validated['HSupPosition'] ?? null,
            'Tool'             => $validated['Tool'] ?? null,

            'Contact1'         => $validated['Contact1'] ?? null,
            'Contact2'         => $validated['Contact2'] ?? null,
            'Contact3'         => $validated['Contact3'] ?? null,
            'Contact4'         => $validated['Contact4'] ?? null,
            'Contact5'         => $validated['Contact5'] ?? null,
            'Contact6'         => $validated['Contact6'] ?? null,
            'ContactOthers'    => $validated['ContactOthers'] ?? null,

            'Working1'         => $validated['Working1'] ?? null,
            'Working2'         => $validated['Working2'] ?? null,
            'WorkingOthers'    => $validated['WorkingOthers'] ?? null,

            'DescriptionSection'  => $validated['DescriptionSection'] ?? null,
            'DescriptionFunction' => $validated['DescriptionFunction'] ?? null,

            'StandardEduc'     => $validated['StandardEduc'] ?? null,
            'StandardExp'      => $validated['StandardExp'] ?? null,
            'StandardTrain'    => $validated['StandardTrain'] ?? null,
            'StandardElig'     => $validated['StandardElig'] ?? null,

            'Supervisor'       => $validated['Supervisor'] ?? null,

            'Core1'            => $validated['Core1'] ?? null,
            'Core2'            => $validated['Core2'] ?? null,
            'Core3'            => $validated['Core3'] ?? null,

            'Corelevel1'       => $validated['Corelevel1'] ?? null,
            'Corelevel2'       => $validated['Corelevel2'] ?? null,
            'Corelevel3'       => $validated['Corelevel3'] ?? null,
            'Corelevel4'       => $validated['Corelevel4'] ?? null,

            'Leader1'          => $validated['Leader1'] ?? null,
            'Leader2'          => $validated['Leader2'] ?? null,
            'Leader3'          => $validated['Leader3'] ?? null,
            'Leader4'          => $validated['Leader4'] ?? null,

            'leaderlevel1'     => $validated['leaderlevel1'] ?? null,
            'leaderlevel2'     => $validated['leaderlevel2'] ?? null,
            'leaderlevel3'     => $validated['leaderlevel3'] ?? null,
            'leaderlevel4'     => $validated['leaderlevel4'] ?? null,

            'structureid'      => $validated['structureid'] ?? null,

        ];


        if ($tempregExt) {
            // Update only the latest row
            DB::table('tempRegAppointmentReorgExt')
                ->where('ID', $tempregExt->ID)
                ->update($data);
        } else {
            // Insert new row if none exists
            DB::table('tempRegAppointmentReorgExt')->insert($data);
        }

        activity('Appointment')
            ->causedBy($user)
            ->withProperties(['updated_employee' => $employeeFullname, 'control_no' => $controlNo,])
            ->log("User '{$user->name}' updated the appointment of employee '{$employeeFullname}'.");
        return response()->json([
            'success' => true,
            'message' => 'Update saved successfully. Please wait for an administrator to review and approve the changes.',
            'xPersonal' => $xPersonal,
            'xtempreg' => $xtempreg,
            'tempregExt' => $tempregExt
            // 'xService' => $xService,
        ]);
    }
}
