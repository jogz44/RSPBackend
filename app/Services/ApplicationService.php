<?php

namespace App\Services;

use App\Jobs\SendApplicantSms;
use App\Mail\EmailApi;
use App\Models\excel\Children;
use App\Models\excel\Civil_service_eligibity;
use App\Models\excel\Education_background;
use App\Models\excel\Learning_development;
use App\Models\excel\nFamily;
use App\Models\excel\nPersonal_info;
use App\Models\excel\Personal_declarations;
use App\Models\excel\references;
use App\Models\excel\skill_non_academic;
use App\Models\excel\Voluntary_work;
use App\Models\excel\Work_experience;
use App\Models\JobBatchesRsp;
use App\Models\Submission;
use App\Models\vwActive;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class ApplicationService
{

    public function applicationCreate(array $validatedData, array $imageFiles = [])
    {

        try {

            // check if the applicant is vwActive employee 
            $firstname = $validatedData['firstname'];
            $lastname  = $validatedData['lastname'];
            $parsebirthdate = $this->normalizeDateForComparison($validatedData['date_of_birth']);

            $birthDate  = $validatedData['date_of_birth'];

            // check if the applicant already applied on the same job post
            $existingSubmission = Submission::whereHas('nPersonalInfo', function ($query) use ($firstname, $lastname, $birthDate) {
                $query->where('firstname', $firstname)
                    ->where('lastname', $lastname)
                    ->where('date_of_birth', $birthDate);
            })
                ->where('job_batches_rsp_id', $validatedData['job_batches_rsp_id'])
                ->first();

            // check if applicant are email are same if not same  sent an error
            $userEmailOnForm = strtolower(trim($validatedData['email_address']));
            $userEmailUseOnOtp  = strtolower(trim($validatedData['email_checker']));

            // check if the applicant use same email
            if ($userEmailOnForm !== $userEmailUseOnOtp) {
                return response()->json([
                    'success' => false,
                    'message' => "Please check your email, it doesn’t match with the email inside the Form.",
                    'email' => "  Your email use on verification:$userEmailUseOnOtp"
                ], 422);
            }

            $employeeExisting = vwActive::whereRaw('UPPER(RTRIM(LTRIM(Surname))) = ?', [strtoupper(trim($lastname))])
                ->whereRaw('UPPER(RTRIM(LTRIM(Firstname))) = ?', [strtoupper(trim($firstname))])
                ->whereDate('BirthDate', $parsebirthdate)
                ->first();

            // check is this employee
            if ($employeeExisting) {
                return response()->json([
                    'success' => false,
                    'message' => "Our records show that you are currently an employee. You are not allowed to apply through this portal.
                     Please coordinate with your HR department for internal job applications Thank you.",
                ], 403);
            }

            // 1. Get current job post
            $currentJob = JobBatchesRsp::findOrFail($validatedData['job_batches_rsp_id']);

            // 2. Get all job posts with the SAME start & end date
            $jobGroupIds = JobBatchesRsp::where('post_date', $currentJob->post_date)
                ->where('end_date', $currentJob->end_date)
                ->pluck('id');

            // 3. Count how many applications the applicant submitted in this job group
            $applicationCount = Submission::whereIn('job_batches_rsp_id', $jobGroupIds)
                ->whereHas('nPersonalInfo', function ($q) use ($firstname, $lastname, $birthDate) {
                    $q->where('firstname', $firstname)
                        ->where('lastname', $lastname)
                        ->where('date_of_birth', $birthDate);
                })
                ->count();

            $post_date = Carbon::parse($currentJob->post_date)->format('F d, Y');
            $end_date   = Carbon::parse($currentJob->end_date)->format('F d, Y');

            // 4. Block if already applied 3 times
            if ($applicationCount >= 3) {
                return response()->json([
                    'success' => false,
                    'message' => "You have already applied for 3 job posts with the same application period (" .
                        $post_date . " to " .     $end_date  . ").",
                ], 422);
            }

            DB::beginTransaction();

            if ($existingSubmission) {
                // update path — reuse existing nPersonalInfo record
                $personal = nPersonal_info::findOrFail($existingSubmission->nPersonalInfo_id);
                $personal->update($validatedData);

                // family is a hasOne — update or create in place
                nFamily::updateOrCreate(
                    ['nPersonalInfo_id' => $personal->id],
                    $validatedData
                );
                $message = 'Successfully updated your application.';
            } else {
                // create path — brand new applicant
                $personal = nPersonal_info::create($validatedData);

                nFamily::create(array_merge(
                    $validatedData,
                    ['nPersonalInfo_id' => $personal->id]
                ));
                $message = 'Successfully submitted your application.';
            }

    
            // hasMany child tables — safe for both create and update:
            // delete() is a no-op if no rows exist yet (new applicant),
            // and clears stale rows if this is a re-submission (existing applicant)
            Children::where('nPersonalInfo_id', $personal->id)->delete();
            Education_background::where('nPersonalInfo_id', $personal->id)->delete();
            Learning_development::where('nPersonalInfo_id', $personal->id)->delete();
            Work_experience::where('nPersonalInfo_id', $personal->id)->delete();
            Voluntary_work::where('nPersonalInfo_id', $personal->id)->delete();
            Civil_service_eligibity::where('nPersonalInfo_id', $personal->id)->delete();
            skill_non_academic::where('nPersonalInfo_id', $personal->id)->delete();
            references::where('nPersonalInfo_id', $personal->id)->delete();
            Personal_declarations::where('nPersonalInfo_id', $personal->id)->delete();

            foreach ($validatedData['children'] ?? [] as $child) {
                Children::create(array_merge($child, ['nPersonalInfo_id' => $personal->id]));
            }

            foreach ($validatedData['school'] ?? [] as $school) {
                if (!empty($school['attachment_path']) && $school['attachment_path'] instanceof \Illuminate\Http\UploadedFile) {
                    $school['attachment_path'] = $school['attachment_path']->store(
                        "applicant_files/{$personal->id}/education",
                        'public'
                    );
                }
                Education_background::create(array_merge($school, ['nPersonalInfo_id' => $personal->id]));
            }

            foreach ($validatedData['training'] ?? [] as $training) {
                if (!empty($training['attachment_path']) && $training['attachment_path'] instanceof \Illuminate\Http\UploadedFile) {
                    $training['attachment_path'] = $training['attachment_path']->store(
                        "applicant_files/{$personal->id}/training",
                        'public'
                    );
                }
                Learning_development::create(array_merge($training, ['nPersonalInfo_id' => $personal->id]));
            }

            foreach ($validatedData['experience'] ?? [] as $experience) {
                if (!empty($experience['attachment_path']) && $experience['attachment_path'] instanceof \Illuminate\Http\UploadedFile) {
                    $experience['attachment_path'] = $experience['attachment_path']->store(
                        "applicant_files/{$personal->id}/experience",
                        'public'
                    );
                }
                Work_experience::create(array_merge($experience, ['nPersonalInfo_id' => $personal->id]));
            }

            foreach ($validatedData['eligibility'] ?? [] as $eligibility) {
                if (!empty($eligibility['attachment_path']) && $eligibility['attachment_path'] instanceof \Illuminate\Http\UploadedFile) {
                    $eligibility['attachment_path'] = $eligibility['attachment_path']->store(
                        "applicant_files/{$personal->id}/eligibility",
                        'public'
                    );
                }
                Civil_service_eligibity::create(array_merge($eligibility, ['nPersonalInfo_id' => $personal->id]));
            }

            foreach ($validatedData['voluntary'] ?? [] as $voluntary) {
                Voluntary_work::create(array_merge($voluntary, ['nPersonalInfo_id' => $personal->id]));
            }


            foreach ($validatedData['skill'] ?? [] as $skill) {
                skill_non_academic::create(array_merge($skill, ['nPersonalInfo_id' => $personal->id]));
            }

            foreach ($validatedData['reference'] ?? [] as $reference) {
                references::create(array_merge($reference, ['nPersonalInfo_id' => $personal->id]));
            }

            foreach ($validatedData['personal_declaration'] ?? [] as $personal_declaration) {
                Personal_declarations::create(array_merge($personal_declaration, ['nPersonalInfo_id' => $personal->id]));
            }

        foreach ($validatedData['other_document'] ?? [] as $document) {
                if (!empty($document['document']) && $document['document'] instanceof \Illuminate\Http\UploadedFile) {
                    $document['document'] = $document['document']->store(
                        "applicant_files/{$personal->id}/other_document",
                        'public'
                    );
                }
            }

            if (!$existingSubmission) {
                Submission::create([
                    'nPersonalInfo_id' => $personal->id,
                    'job_batches_rsp_id' => $validatedData['job_batches_rsp_id'],
                ]);
            }


            // Send Email and sms
            $this->sendApplicantEmail($personal, $validatedData['job_batches_rsp_id'], false);
            $this->sendApplicantSms($personal, $validatedData['job_batches_rsp_id'], false);
            DB::commit();

            return [
                'message' => $message,
                'data'    => $personal->load('family', 'children', 'education', 'training', 'work_experience', 'voluntary_work', 'eligibity', 'skills', 'references', 'personal_declarations'),
            ];
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }
    }

    private function normalizeDateForComparison($date): ?string
    {
        if (empty($date)) {
            return null;
        }

        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d',
            'd/m/Y',
            'm/d/Y',
        ];

        foreach ($formats as $format) {
            try {
                return \Carbon\Carbon::createFromFormat($format, trim($date))->format('Y-m-d');
            } catch (\Exception $e) {
                continue;
            }
        }

        try {
            return \Carbon\Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
    private function sendApplicantEmail($applicant, $jobId, $isUpdate)
    {
        $job = \App\Models\JobBatchesRsp::findOrFail($jobId);

        $subject = $isUpdate ? 'Application Updated' : 'Application Received';

        $template = 'mail-template.application';

        $lastname = $applicant->lastname;
        $firstname = $applicant->firstname;
        $full_name = trim("$firstname $lastname");


        Mail::to($applicant->email_address)->queue((new EmailApi(
            $subject,
            $template,
            [
                'mailSubject' => $subject,
                // 'firstname' => $applicant->firstname,
                // 'lastname' => $applicant->lastname,
                'full_name' => $full_name,
                'Office' => $job->Office,
                'Position' => $job->Position,
                'ItemNo' => $job->ItemNo,
                'isUpdate' => $isUpdate,
            ]


        ))->onQueue('emails'));

        \App\Models\EmailLog::create([
            'email' => $applicant->email_address,
            'activity' => $subject,
        ]);
    }

    // ── SMS ───────────────────────────────────────────────────────────
    private function sendApplicantSms($applicant, $jobId, $isUpdate): void
    {
        $job           = \App\Models\JobBatchesRsp::findOrFail($jobId);
        $fullName      = trim("{$applicant->firstname} {$applicant->lastname}");
        $contactNumber = $this->normalizePhoneNumber($applicant->cellphone_number ?? null); // 👈 normalize here


        if (!$contactNumber) {
            // Log::info('No valid contact number for applicant, skipping SMS', [
            //     'raw_number' => $applicant->cellphone_number ?? 'null',
            // ]);
            return;
        }

        $smsMessage = $isUpdate
            ? "Dear {$fullName},\n\n"
            . "Your application has been UPDATED.\n\n"
            . "Position: {$job->Position}\n"
            . "Item No: {$job->ItemNo}\n"
            . "Office: {$job->Office}\n\n"
            . "Please check your email for full details.\n\n"
            . "Thank you!"
            : "Dear {$fullName},\n\n"
            . "We acknowledge receipt of your application.\n\n"
            . "Position: {$job->Position}\n"
            . "Item No: {$job->ItemNo}\n"
            . "Office: {$job->Office}\n\n"
            . "Your application is currently under review by our HRMPSB Secretariat.\n\n"
            . "Please check your email for full details.\n\n"
            . "Thank you!";

        SendApplicantSms::dispatch($contactNumber, $smsMessage)
            ->onQueue('sms');
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


        return null;
    }

}
