<?php

namespace App\Services;

use App\Jobs\SendApplicantSms;
use App\Mail\EmailApi;
use App\Models\EmailLog;
use App\Models\JobBatchesRsp;
use App\Models\Submission;
use App\Models\xPersonal;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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



    // /**
    //  * Send applicant confirmation or update email.
    //  */



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

    // employee applying  on the job post  storing his application on submission table
    public function employeeApplicant(array $validated, array $images = [])
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
                'xPersonalAddt.EmailAdd',
                'xPersonalAddt.CellphoneNo as cellphone_number',
            )
            ->where('xPersonal.ControlNo', $controlNo)
            ->first();

        if (!$applicant) {
            return response()->json(['message' => 'Applicant not found.'], 404);
        }

        $firstname = $applicant->Firstname;
        $lastname  = $applicant->Surname;
        $birthdate = $applicant->BirthDate;

        $currentJob  = JobBatchesRsp::findOrFail($jobId);
        $jobGroupIds = JobBatchesRsp::where('post_date', $currentJob->post_date)
            ->where('end_date', $currentJob->end_date)
            ->pluck('id');

        $applicationCount = DB::table('submission')
            ->join('xPersonal', 'xPersonal.ControlNo', '=', 'submission.ControlNo')
            ->whereIn('submission.job_batches_rsp_id', $jobGroupIds)
            ->where('xPersonal.Firstname', $firstname)
            ->where('xPersonal.Surname',   $lastname)
            ->where('xPersonal.BirthDate', $birthdate)
            ->count();

        $post_date = Carbon::parse($currentJob->post_date)->format('F d, Y');
        $end_date  = Carbon::parse($currentJob->end_date)->format('F d, Y');

        if ($applicationCount >= 3) {
            return response()->json([
                'success' => false,
                'message' => "$firstname $lastname, You have already applied for 3 job posts with the same application period ($post_date to $end_date)."
            ], 422);
        }

        // ✅ Check if already applied for this specific job
        $existing = DB::table('submission')
            ->join('xPersonal', 'xPersonal.ControlNo', '=', 'submission.ControlNo')
            ->where('submission.job_batches_rsp_id', $jobId)
            ->where('xPersonal.Firstname', $firstname)
            ->where('xPersonal.Surname',   $lastname)
            ->where('xPersonal.BirthDate', $birthdate)
            ->first();

        // ✅ Already applied — allow updating missing documents
        if ($existing) {
            $updatedCategories = [];
            $skippedCategories = [];

            if (!empty($images)) {
                $allowedCategories = ['education', 'training', 'experience', 'eligibility'];

                foreach ($allowedCategories as $category) {
                    if (empty($images[$category])) continue;

                    // ✅ Now includes job_id so each job has its own folder
                    // $folderPath      = "applicant_files/{$controlNo}/job_{$jobId}/{$category}";
                    // $alreadyHasFiles = Storage::disk('public')->exists($folderPath)
                    //     && count(Storage::disk('public')->files($folderPath)) > 0;

                    // if ($alreadyHasFiles) {
                    //     $skippedCategories[] = $category;
                    //     continue;
                    // }

                    // ✅ Pass jobId to store in correct folder
                    // $this->storeEmployeeImages([$category => $images[$category]], $controlNo, $jobId);
                    // $updatedCategories[] = $category;
                    $this->storeEmployeeImages([$category => $images[$category]], $controlNo, $jobId);
                    $updatedCategories[] = $category;
                }
            }

            $updatedMsg  = !empty($updatedCategories)
                ? ' Documents added for: ' . implode(', ', $updatedCategories) . '.'
                : '';
            $skippedMsg  = !empty($skippedCategories)
                ? ' Already uploaded (skipped): ' . implode(', ', $skippedCategories) . '.'
                : '';
            $noChangeMsg = empty($updatedCategories) && empty($skippedCategories)
                ? ' No new documents were provided.'
                : '';

            return response()->json([
                'success'            => true,
                'message'            => "$firstname $lastname, you have already applied for this job.{$updatedMsg}{$skippedMsg}{$noChangeMsg}",
                'submission_id'      => $existing->id ?? null,
                'updated_categories' => $updatedCategories,
                'skipped_categories' => $skippedCategories,
            ], 200);
        }

        // ✅ CREATE NEW SUBMISSION
        $submit = Submission::create([
            'ControlNo'          => $controlNo,
            'status'             => 'pending',
            'job_batches_rsp_id' => $jobId,
            'nPersonalInfo_id'   => null,
        ]);

        // ✅ STORE IMAGES — pass jobId for correct folder
        if (!empty($images)) {
            $this->storeEmployeeImages($images, $controlNo, $jobId);
        }

        // ✅ SEND EMAIL
        if (!empty($applicant->EmailAdd)) {
            $emailApplicant = (object) [
                'email_address' => $applicant->EmailAdd,
                'firstname'     => $firstname,
                'lastname'      => $lastname,
            ];

            $this->sendApplicantEmail($emailApplicant, $jobId, false);
            $this->sendApplicantSms($applicant, $jobId, false);
        }

        return response()->json([
            'success' => true,
            'message' => 'Submission created successfully and email sent.',
            'data'    => $submit,
        ], 201);
    }

    // ✅ Now accepts $jobId to separate files per job application
    private function storeEmployeeImages(array $images, string $controlNo, int $jobId): array
    {
        $allowedCategories = ['education', 'training', 'experience', 'eligibility'];
        $storedPaths       = [];

        foreach ($allowedCategories as $category) {
            $files = $images[$category] ?? [];
            if (empty($files)) continue;

            if (!is_array($files)) {
                $files = [$files];
            }

            $storedPaths[$category] = [];

            foreach ($files as $file) {
                if (!$file instanceof \Illuminate\Http\UploadedFile) continue;

                $fileName     = $this->generateFileName($file);

                // ✅ job_{jobId} separates files per job application
                $relativePath = "applicant_files/{$controlNo}/job_{$jobId}/{$category}";

                $file->storeAs($relativePath, $fileName, 'public');

                $storedPaths[$category][] = "{$relativePath}/{$fileName}";
            }
        }

        return $storedPaths;
    }
    private function generateFileName($file): string
    {
        if ($file instanceof \Illuminate\Http\UploadedFile) {
            $extension = $file->getClientOriginalExtension();
        } elseif ($file instanceof \SplFileInfo) {
            $extension = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
        } else {
            $extension = '';
        }

        return time() . '_' . Str::random(8) . '.' . $extension;
    }



    // send emails to the applicant if he/she apply or update his/her application
    private function sendApplicantEmail($applicant, $jobId, $isUpdate)
    {
        $job = \App\Models\JobBatchesRsp::findOrFail($jobId);

        $subject = $isUpdate ? 'Application Updated' : 'Application Received';

        $template = 'mail-template.application';

           $firstname = $applicant->firstname;
            $lastname = $applicant->lastname;


            $full_name = trim("$firstname $lastname");

        Mail::to($applicant->email_address)->queue((new EmailApi(
            $subject,
            $template,
            [
                'mailSubject' => $subject,
                // 'firstname' => $applicant->firstname,
                // 'lastname' => $applicant->lastname,
                'full_name' => $full_name,
                'Office'      => $job->Office,    // 👈 renamed from jobOffice
                'Position'    => $job->Position,  // 👈 renamed from jobPosition
                'ItemNo'    => $job->ItemNo,  // 👈 renamed from jobPosition
                'isUpdate' => $isUpdate,
            ]


        ))->onQueue('emails'));

        EmailLog::create([
            'email' => $applicant->email_address,
            'activity' => $subject,
        ]);
    }

    private function normalizePhoneNumber(?string $number): ?string
    {
        if (!$number) return null;

        // If multiple numbers separated by / or comma, take the FIRST one only
        $number = preg_split('/[\/,]/', $number)[0];
        $number = trim($number);

        // Remove all non-numeric characters (spaces, dashes, parentheses, +)
        $cleaned = preg_replace('/\D/', '', $number);

        // Handle +639XXXXXXXXX → 09XXXXXXXXX (international format, 12 digits)
        if (str_starts_with($cleaned, '639') && strlen($cleaned) === 12) {
            $cleaned = '0' . substr($cleaned, 2);
        }

        // Must be exactly 11 digits starting with 09
        if (strlen($cleaned) === 11 && str_starts_with($cleaned, '09')) {
            return $cleaned;
        }

        // Invalid — too short, too long, or wrong prefix
        // Log::warning('Invalid phone number, skipping SMS', [
        //     'original' => $number,
        //     'cleaned'  => $cleaned,
        //     'length'   => strlen($cleaned),
        // ]);

        return null;
    }

    // ── SMS ───────────────────────────────────────────────────────────
    private function sendApplicantSms($applicant, $jobId, $isUpdate): void
    {
        $job           = \App\Models\JobBatchesRsp::findOrFail($jobId);
        $fullName      = trim("{$applicant->Firstname} {$applicant->Surname}");
        $contactNumber = $this->normalizePhoneNumber($applicant->cellphone_number ?? null); // 👈 normalize here


        if (!$contactNumber) {
            // Log::info('No valid contact number for applicant, skipping SMS', [
            //     'raw_number' => $applicant->cellphone_number ?? 'null',
            // ]);
            return;
        }


        $smsMessage = $isUpdate
            ? "Dear {$fullName}, your application for {$job->Position} (Item No. {$job->ItemNo})"
                    . "under {$job->Office} has been updated. "
                    . "Please check your email for full details. Thank you!"
            :  "Dear {$fullName}, we acknowledge receipt of your application for "
                    . "{$job->Position} (Item No. {$job->ItemNo}) under {$job->Office}. "
                    . "Your application is currently under review by our HRMPSB Secretariat. "
                    . "Please check your email for full details. Thank you!";

        SendApplicantSms::dispatch($contactNumber, $smsMessage)
            ->onQueue('sms');
    }


    //getting the image for the employee using the control number and the path stored in the database and return it as a response
    public function getEmployeePhoto($ControlNo)
    {

        $employee = xPersonal::where('ControlNo', $ControlNo)->select('Pics')->first();

        if (!$employee || !$employee->Pics) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        // Convert Windows UNC path to accessible path
        // \\192.168.2.205\Payroll Database\... → //192.168.2.205/Payroll Database/...
        $path = str_replace('\\', '/', $employee->Pics);
        $path = ltrim($path, '/');
        // Result: 192.168.2.205/Payroll Database/IDPICTURE/.../filename.jpg

        // Full UNC for file_get_contents (Linux uses smb:// or mapped path)
        // If Laravel server is Windows and has access to the share:
        $windowsPath = $employee->Pics; // use raw UNC path directly

        if (!file_exists($windowsPath)) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        $fileContents = file_get_contents($windowsPath);
        $mimeType = mime_content_type($windowsPath) ?: 'image/jpeg';

        return response($fileContents, 200)
            ->header('Content-Type', $mimeType)
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
