<?php

namespace App\Services;

use App\Mail\EmailApi;
use App\Models\JobBatchesRsp;
use App\Models\OnCriteriaJob;
use App\Models\Submission;
use App\Models\TempRegAppointmentReorgExt;
use App\Models\TempRegHistory;
use App\Models\xService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;


class ApplicantHiringService

{

    public function hireApplicant($request)
    {
        $validated = $request->validate([
            'hires'                 => 'required|array|min:1',
            'hires.*.submission_id' => 'required|distinct|exists:submission,id',

            // service
            'hires.*.SepDate'   => 'nullable|date',
            'hires.*.SepCause'  => 'nullable|string|max:255',

            // tempreg
            'hires.*.sepdate'   => 'nullable|date',
            'hires.*.sepcause'  => 'nullable|string|max:255',
            'hires.*.vicename'  => 'nullable|string|max:255',
            'hires.*.vicecause' => 'nullable|string|max:255',
        ]);

        $results = [];

        foreach ($validated['hires'] as $hire) {
            try {
                $results[] = $this->hireSingleApplicant($hire, $request);
            } catch (\Throwable $e) {
                Log::error('hireApplicant failed', [
                    'submission_id' => $hire['submission_id'],
                    'error'         => $e->getMessage(),
                    'line'          => $e->getLine(),
                ]);

                $results[] = [
                    'submission_id' => $hire['submission_id'],
                    'success'       => false,
                    'message'       => $e->getMessage(),
                ];
            }
        }

        $succeeded = collect($results)->where('success', true)->count();
        $total     = count($results);

        return response()->json([
            'success'   => $succeeded === $total,
            'message'   => "{$succeeded} of {$total} applicant(s) hired successfully.",
            'succeeded' => $succeeded,
            'failed'    => $total - $succeeded,
            'results'   => $results,
        ], $succeeded === $total ? 200 : ($succeeded > 0 ? 207 : 422));
    }


    private function hireSingleApplicant(array $hire, $request): array
    {
        $submissionId = $hire['submission_id'];

        return DB::transaction(function () use ($hire, $request, $submissionId,) {

            $rawFromDate = DB::table('temporary_xservices')
                ->where('submission_id', $submissionId)

                ->value('FromDate');

            if (!$rawFromDate) {
                throw new \RuntimeException(
                    'No temporary appointment date found for this submission. Save the temporary hire first.'
                );
            }

            $fromDate = Carbon::parse($rawFromDate);


            $submission = Submission::with([
                'nPersonalInfo.children',
                'nPersonalInfo.family',
                'nPersonalInfo.work_experience',
                'nPersonalInfo.eligibity',
                'nPersonalInfo.education',
                'nPersonalInfo.voluntary_work',
                'nPersonalInfo.training',
                'nPersonalInfo.references',
                'nPersonalInfo.skills',
                'nPersonalInfo.personal_declarations',
            ])->findOrFail($submissionId);

            $jobPost = JobBatchesRsp::findOrFail($submission->job_batches_rsp_id);

            // // Check first, before any insert
            // if ($jobPost->status === 'Occupied') {
            //     throw new \RuntimeException('This job post is already occupied.');
            // }

            $applicant = $submission->nPersonalInfo;

            if (!$applicant && !$submission->ControlNo) {
                throw new \RuntimeException('Applicant personal info not found.');
            }

            if (!$applicant) {
                // Already an employee
                $finalControlNo = $submission->ControlNo;
            } else {
                $family                = $applicant->family;
                $personal_declarations = $applicant->personal_declarations->first();

                $existingControlNo = $applicant->control_no
                    ?? $applicant->controlno
                    ?? $applicant->ControlNo
                    ?? $submission->ControlNo
                    ?? null;

                if (!$existingControlNo) {
                    $existingInDB = DB::table('xPersonal')
                        ->where('Surname',    $applicant->lastname)
                        ->where('Firstname',  $applicant->firstname)
                        ->where('Middlename', $applicant->middlename)
                        ->first();

                    if ($existingInDB) {
                        $existingControlNo = $existingInDB->ControlNo;
                    }
                }

                $finalControlNo = $existingControlNo ?? $this->generateControlNo();

                $alreadyInXPersonal = DB::table('xPersonal')
                    ->where('ControlNo', $finalControlNo)
                    ->exists();

                if (!$alreadyInXPersonal) {
                    $this->insertPersonalInfo($applicant, $family, $personal_declarations, $finalControlNo, $submissionId);
                    $this->insertxPersonalAddt($applicant, $family, $personal_declarations, $finalControlNo, $submissionId);
                    $this->insertChildren($applicant->children, $finalControlNo, $submissionId);
                    $this->insertWorkExperience($applicant->work_experience, $finalControlNo, $submissionId);
                    $this->insertEligibility($applicant->eligibity, $finalControlNo, $submissionId);
                    $this->insertEducation($applicant->education, $finalControlNo, $submissionId);
                    $this->insertVoluntaryWork($applicant->voluntary_work, $finalControlNo, $submissionId);
                    $this->insertTraining($applicant->training, $finalControlNo, $submissionId);
                    $this->insertSkills($applicant->skills, $finalControlNo, $submissionId);
                    $this->insertNonAcademic($applicant->skills, $finalControlNo, $submissionId);
                    $this->insertOrganization($applicant->skills, $finalControlNo, $submissionId);
                    $this->insertReferences($applicant->references, $finalControlNo, $submissionId);
                    $this->insertPWD($personal_declarations, $finalControlNo, $submissionId);
                    $this->insertxPersonalDiversity($applicant, $personal_declarations, $finalControlNo, $submissionId);
                }
            }

            $prevSubmissionStatus = $submission->status;
            $prevJobPostStatus    = $jobPost->status;

            // $jobPost->update(['status' => 'Occupied']);

            $submission->update([

                'temporary_hired' => false,
            ]);
            $temporary_tempregReorg = DB::table('temporary_tempreg_appointment_reorg')
                ->where('submission_id', $submissionId)
                ->first();

            if (!$temporary_tempregReorg) {
                throw new \RuntimeException('No temporary reorg record found for this submission.');
            }


            $temporary_tempreg_appointment_reorg_ext = DB::table('temporary_tempreg_appointment_reorg_ext')
                ->where('submission_id', $submissionId)
                ->first();

            if (!$temporary_tempreg_appointment_reorg_ext) {
                throw new \RuntimeException('No temporary reorg record found for this submission.');
            }


            $this->updatePlantillaStructure(
                $jobPost,
                $finalControlNo,
                $hire['SepDate']   ?? null,
                $hire['SepCause']  ?? null,
                $hire['sepdate']   ?? null,
                $hire['sepcause']  ?? null,
                $hire['vicename']  ?? null,
                $hire['vicecause'] ?? null,
                $fromDate,
                $submissionId,
                $temporary_tempregReorg,
                $temporary_tempreg_appointment_reorg_ext
            );

            $tables = [
                'temporary_tempreg_appointment_reorg_ext', // child muna
                'temporary_tempreg_appointment_reorg',
                'temporary_xservices',
                'temporary_posting_dates',
                'temporary_x_personal',
            ];

            foreach ($tables as $table) {
                DB::table($table)->where('submission_id', $submissionId)->delete();
            }

            $user = Auth::user();

            if ($user instanceof \App\Models\User) {
                $position = $jobPost->Position ?? $jobPost->position ?? 'Unknown Position';

                activity($user->username)
                    ->causedBy($user)
                    ->performedOn($submission)
                    ->withProperties([
                        'username'         => $user->username,
                        'office'           => $user->office,
                        'job_position'     => $position,
                        'hired_control_no' => $finalControlNo,
                        'submission_id'    => $submission->id,
                        'ip_address'       => $request->ip(),
                        'user_agent'       => $request->header('User-Agent'),
                    ])
                    ->log("{$user->name} hired applicant (ControlNo: {$finalControlNo}) for job post {$jobPost->id}.");
            }

            DB::table('hire_rollbacks')->insert([
                'controlNo'              => $finalControlNo,
                'submission_id'          => $submissionId,
                'job_batches_rsp_id'     => $jobPost->id,
                'prev_submission_status' => $prevSubmissionStatus,
                'prev_job_post_status'   => $prevJobPostStatus,
                'expired_at'             => Carbon::now()->addDays(3),
                'created_at'             => Carbon::now(),
                'updated_at'             => Carbon::now(),
            ]);

            return [
                'submission_id' => $submissionId,
                'success'       => true,
                'message'       => 'Applicant hired successfully.',
                'control_no'    => $finalControlNo,
                'job_post'      => $jobPost->id,
            ];
        });
    }


    private function generateControlNo()
    {
        $maxControlNo = DB::table('xPersonal')->max('ControlNo');
        $nextNumber   = $maxControlNo ? intval($maxControlNo) + 1 : 1;
        return str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
    }

    private function insertPersonalInfo($applicant, $family,  $personal_declarations, $controlNo, $submissionId)
    {
        DB::table('xPersonal')->insert([
            'ControlNo'    => $controlNo,
            'Surname'      => $this->upper($applicant->lastname),
            'Firstname'    =>  $this->upper($applicant->firstname),
            'Middlename'   =>  $this->upper($applicant->middlename),
            'Sex'          =>  $this->upper($applicant->sex),
            'CivilStatus'  => $this->upper($applicant->civil_status),
            'BirthDate'    => $this->formatDateTime($applicant->date_of_birth),
            'BirthPlace'   => $this->upper($applicant->place_of_birth),
            'Address'      => $this->upper(trim(($applicant->residential_house) . ' ' .
                ($applicant->Rpurok) . ' ' .
                ($applicant->residential_street) . ' ' .
                ($applicant->residential_barangay) . ' ' .
                ($applicant->residential_city) . ' ' .
                ($applicant->residential_province))),


            'Citizenship'  => $this->upper($applicant->citizenship),
            'Religion'     => $this->upper($applicant->religion),
            'Heights'      => $applicant->height ?? null,
            'Weights'      => $applicant->weight ?? null,
            'BloodType'    => $this->upper($applicant->blood_type),
            'TelNo'        => $applicant->telephone_number ?? null,
            'TINNo'        => $applicant->tin_no ?? null,
            'GSISNo'       => $applicant->gsis_no ?? null,
            'PAGIBIGNo'    => $applicant->pagibig_no ?? null,
            'SSSNo'        => $applicant->sss_no ?? null,
            'PHEALTHNo'    => $applicant->philhealth_no ?? null,
            // 'Pics' => $applicant->image_path ?? null,
            'FatherName'   => $this->upper($family->father_lastname),
            'MotherName'   => $this->upper($family->mother_lastname),
            'MaidenName'   => $this->upper($family->spouse_middlename),
            'SpouseName'   => $this->upper($family->spouse_name),
            'Occupation'   => $this->upper($family->spouse_occupation),
            // need to fix identify the  what is the q-r
            // 34
            'Q1' =>  $personal_declarations->{'question_34a'} ?? 'No',
            'R11' => $personal_declarations->{'question_34b'} ?? 'No',
            'Q11' =>  $personal_declarations->{'response_34'} ?? null,


            //35
            'Q4' =>  $personal_declarations->{'question_35a'}  ?? 'No',
            'R4' =>  $personal_declarations->{'response_35a'}  ?? null,

            'Q7' =>  $personal_declarations->{'question_35b'} ?? 'No',
            'R7' =>  $personal_declarations->{'response_35b_status'}  ?? null,



            // 36
            'Q3' =>  $personal_declarations->{'question_36'} ?? 'No',
            'R3' =>  $personal_declarations->{'response_36'} ?? null,


            //37

            'Q5' =>  $personal_declarations->{'question_37'}  ?? 'No',
            'R5' =>  $personal_declarations->{'response_37'}  ?? null,


            //38

            'Q6' =>  $personal_declarations->{'question_38a'} ?? 'No',
            'R6' =>  $personal_declarations->{'response_38a'}   ?? null,


            'submission_id' => $submissionId

        ]);
    }

    private function insertPWD($personal_declarations, $controlNo, $submissionId)
    {
        DB::table('xPWD')->insert([
            'Controlno'    => $controlNo,
            'chronic'    => $personal_declarations->chronic,
            'Psychosocial'    => $personal_declarations->Psychosocial,
            'Orthopedic'    => $personal_declarations->Orthopedic,
            'Communication'    => $personal_declarations->Communication,
            'Learning'    => $personal_declarations->Learning,
            'Mental'    => $personal_declarations->Mental,
            'Visual'    => $personal_declarations->Visual,
            'submission_id' => $submissionId

        ]);
    }

    private function insertxPersonalDiversity($applicant, $personal_declarations, $controlNo, $submissionId)
    {
        // Map boolean fields to their text labels
        $pwdLabels = [
            'Visual'       => 'Visual Disability',
            'Mental'       => 'Mental Disability',
            'Learning'     => 'Learning Disability',
            'Communication' => 'Communication Disability',
            'Orthopedic'   => 'Orthopedic Disability',
            'Psychosocial' => 'Psychosocial Disability',
            'chronic'      => 'Disability Caused by Chronic Illness',
        ];

        // Collect all PWD types where the value is truthy (1 / true)
        $pwdTypes = [];
        foreach ($pwdLabels as $field => $label) {
            if (!empty($personal_declarations->$field)) {
                $pwdTypes[] = $label;
            }
        }

        // Join multiple disabilities with comma, or fallback to 'N/A'
        $pwd = !empty($pwdTypes) ? implode(', ', $pwdTypes) : 'N/A';

        $soloParent = (strtoupper($personal_declarations->{'question_40c'} ?? 'NO') === 'YES') ? 1 : 0;


        DB::table('xPersonalDiversity')->insert([
            'controlno'      => $controlNo,
            'Religion'       => $this->upper($applicant->religion),
            'ethnicity'      => $this->upper($applicant->ethnic_group),
            'indigenousGroup' => $personal_declarations->{'response_40a'}  ?? null,
            'PWD'            => $pwd,
            'SoloParent'     => $soloParent,
            'submission_id'  => $submissionId,
        ]);
    }

    private function insertxPersonalAddt($applicant, $family, $personal_declarations, $controlNo, $submissionId)
    {
        DB::table('xPersonalAddt')->insert([
            'ControlNo'    => $controlNo,

            'EmailAdd'    => $applicant->email_address,
            'CellphoneNo'    => $applicant->cellphone_number,

            'SpouseFirstname'    => $this->upper($family->spouse_firstname),
            'SpouseMiddlename'   => $this->upper($family->spouse_middlename),
            'SpouseEmployer'    => $this->upper($family->spouse_employer),
            'SpouseEmpAddress'    => $this->upper($family->spouse_employer_address),
            'SpouseEmpTel'    => $family->spouse_employer_telephone,

            'FatherFirstname'   => $this->upper($family->father_firstname),
            'FatherMiddlename'   => $this->upper($family->father_middlename),

            'MotherFirstname'    => $this->upper($family->mother_firstname),
            'MotherMiddlename'     => $this->upper($family->mother_middlename),

            'datefiled' =>  $personal_declarations->{'response_35b_date'}  ?? null,

            //38
            'local' =>  $personal_declarations->{'question_38b'} ?? null,
            'localdetails' =>  $personal_declarations->{'response_38b'}   ?? null,


            //39

            'country' =>  $personal_declarations->{'question_39'} ?? null,
            'countrydetails' =>  $personal_declarations->{'response_39'} ?? null,



            // 'question_40a', IP
            // 'response_40a', IPR
            'IP'      => $personal_declarations->{'question_40a'} ?? 'NO',
            'IPR'      => $personal_declarations->{'response_40a'}  ?? null,

            // 'question_40b', PWD
            // 'response_40b', PWDR
            'PWD'      => $personal_declarations->{'question_40b'} ?? 'NO',
            'PWDR'      => $personal_declarations->{'response_40b'} ?? null,


            // 'question_40c', SOLO
            // 'response_40c',  SOLOPR
            'SoloP'      => $personal_declarations->{'question_40c'} ?? 'NO',
            'SoloPR'      => $personal_declarations->{'response_40c'} ?? null,



            'Rhouse' => $this->upper($applicant->residential_house),
            'Rstreet'      =>  $this->upper($applicant->residential_street),
            'Rpurok'      => $this->upper($applicant->Rpurok),
            'Rsubdivision'      =>  $this->upper($applicant->residential_subdivision),
            'Rbarangay'      =>  $this->upper($applicant->residential_barangay),
            'Rprovince'      =>  $this->upper($applicant->residential_province),
            'Rregion'      =>  $this->upper($applicant->residential_region),
            'Rcity'      =>  $this->upper($applicant->residential_city),
            'Rzip'      =>  $applicant->residential_zip ?? '',

            'Pregion'      => $this->upper($applicant->permanent_region),
            'Phouse'      => $this->upper($applicant->permanent_house),
            'Ppurok'      => $this->upper($applicant->Ppurok),
            'Pstreet'      => $this->upper($applicant->permanent_street),
            'Psubdivision'      => $this->upper($applicant->permanent_subdivision),
            'Pbarangay'      => $this->upper($applicant->permanent_barangay),
            'Pcity'      => $this->upper($applicant->permanent_city),
            'Pprovince'      => $this->upper($applicant->permanent_province),
            'Pzip'      => $applicant->permanent_zip ?? '',

            'local'      => 0,

            'localdetails'      => '',
            'country'      => 0,

            'countrydetails'      => '',
            'datefiled'      => '',
            'gender'      => $this->upper($applicant->gender_prefer),
            'citizenshipStatus'      => $this->upper($applicant->citizenship_status),
            'birthcountry'      => '',
            'submission_id' => $submissionId


        ]);
    }

    private function insertChildren($children, $controlNo, $submissionId)
    {
        foreach ($children ?? [] as $child) {
            DB::table('xChildren')->insert([
                'ControlNo' => $controlNo,
                'ChildName' =>  $this->upper($child->child_name),
                'BirthDate' => $this->formatDateTime($child->birth_date),
                'submission_id' => $submissionId
            ]);
        }
    }

    private function insertWorkExperience($experiences, $controlNo, $submissionId)
    {
        foreach ($experiences ?? [] as $exp) {
            DB::table('xExperience')->insert([
                'CONTROLNO'  => $controlNo,
                'WFrom'      => $this->formatDate($exp->work_date_from),
                'WTo'        => $this->formatDate($exp->work_date_to),
                'WPosition'  =>  $this->upper($exp->position_title),
                'WCompany'   =>  $this->upper($exp->department),
                'WSalary'    => $exp->monthly_salary,
                'WGrade'     => $exp->salary_grade,
                'Status'     =>  $this->upper($exp->status_of_appointment),
                'WGov'       =>  $this->upper($exp->government_service),
                'submission_id' => $submissionId
            ]);
        }
    }

    private function insertEligibility($eligibilities, $controlNo, $submissionId)
    {
        foreach ($eligibilities ?? [] as $eli) {
            DB::table('xCivilService')->insert([
                'ControlNo'   => $controlNo,
                'CivilServe'  =>  $this->upper($eli->eligibility),
                'Dates'       => $eli->date_of_examination,
                'Rates'       => $eli->rating,
                'Place'       =>  $this->upper($eli->place_of_examination),
                'LNumber'     => $eli->license_number,
                'LDate'       => $eli->date_of_validity,
                'submission_id' => $submissionId
            ]);
        }
    }

    private function insertEducation($educations, $controlNo, $submissionId)
    {
        foreach ($educations ?? [] as $edu) {
            DB::table('xEducation')->insert([
                'ControlNo'   => $controlNo,
                'Education'   =>  $this->upper($edu->level),
                'School'      =>  $this->upper($edu->school_name),
                'Degree'      =>  $this->upper($edu->degree),
                'NumUnits'    => is_numeric($edu->highest_units) ? (float) $edu->highest_units : 0.0,
                'YearLevel'   => $edu->year_graduated ?? '',
                'DateAttend'  => $edu->attendance_from . ' - ' . $edu->attendance_to,
                'Honors'      =>  $this->upper($edu->scholarship),
                'Graduated'   => $edu->attendance_to,
                'submission_id' => $submissionId
            ]);
        }
    }

    private function insertVoluntaryWork($works, $controlNo, $submissionId)
    {
        foreach ($works ?? [] as $work) {
            DB::table('xNGO')->insert([
                'CONTROLNO'   => $controlNo,
                'OrgName'     =>  $this->upper($work->organization_name),
                'DateFrom'      => $this->formatDate($work->inclusive_date_from),
                'DateTo'        => $this->formatDate($work->inclusive_date_to),
                'NoHours'     => $work->number_of_hours,
                'OrgPosition' => $work->position,
                'submission_id' => $submissionId
            ]);
        }
    }

    private function insertTraining($trainings, $controlNo, $submissionId)
    {
        foreach ($trainings ?? [] as $train) {
            if (!$train) continue;
            DB::table('xTrainings')->insert([
                'ControlNo'   => $controlNo,
                'Training'    =>  $this->upper($train->training_title),
                'Dates'       => ($train->inclusive_date_from ?? '') . ' - ' . ($train->inclusive_date_to ?? ''),
                'NumHours'    => $train->number_of_hours ?? 0,
                'Conductor'   =>  $this->upper($train->conducted_by),
                'DateFrom' => $this->parseDate($train->inclusive_date_from, 'm/d/Y'),
                'DateTo'   => $this->parseDate($train->inclusive_date_to, 'm/d/Y'),
                'Type'        =>  $this->upper($train->type),
                'submission_id' => $submissionId
            ]);
        }
    }

    private function insertSkills($skills, $controlNo, $submissionId)
    {
        foreach ($skills ?? [] as $skill) {
            DB::table('xSkills')->insert([
                'ControlNo' => $controlNo,
                'Skills'    =>  $this->upper($skill->skill),
                'submission_id' => $submissionId
            ]);
        }
    }

    private function insertNonAcademic($academics, $controlNo, $submissionId)
    {
        foreach ($academics ?? [] as $acad) {
            DB::table('xNonAcademic')->insert([
                'ControlNo'   => $controlNo,
                'NonAcademic' =>  $this->upper($acad->non_academic),
                'submission_id' => $submissionId
            ]);
        }
    }

    private function insertOrganization($organizations, $controlNo, $submissionId)
    {
        foreach ($organizations ?? [] as $org) {
            DB::table('xOrganization')->insert([
                'ControlNo'     => $controlNo,
                'Organization'  =>  $this->upper($org->organization),
                'submission_id' => $submissionId
            ]);
        }
    }

    private function insertReferences($references, $controlNo, $submissionId)
    {
        foreach ($references ?? [] as $ref) {
            DB::table('xReference')->insert([
                'ControlNo' => $controlNo,
                'Names'     =>  $this->upper($ref->full_name),
                'Address'   =>  $this->upper($ref->address),
                'TelNo'     => $ref->contact_number,
                'submission_id' => $submissionId
            ]);
        }
    }

    private function updatePlantillaStructure($jobPost, $controlNo, $SepDate_service, $SepCause_service, $sepdate, $sepcause, $vicename, $vicecause, $fromDate, $submissionId, $temporary_tempregReorg, $temporary_tempreg_appointment_reorg_ext)
    {

        $tblStructureDetails_ID = $jobPost->tblStructureDetails_ID;
        $itemNo = $jobPost->ItemNo;
        $pageNo = $jobPost->PageNo;


        $activeService = DB::table('xService')
            ->where('ControlNo', $controlNo)
            ->orderBy('ToDate', 'DESC')
            ->orderBy('FromDate', 'DESC')
            ->first();

        if ($activeService) {

            // Regular / Casual — dapat magkaroon ng SepDate at SepCause
            if (in_array($activeService->Status, ['REGULAR', 'CASUAL'])) {

                // CASUAL -> REAPPOINTMENT, REGULAR -> PROMOTION
                $sepCause = ($activeService->Status === 'REGULAR') ? 'PROMOTION' : 'REAPPOINTMENT';

                if (!empty($activeService->SepDate)) {
                    // May laman na ang SepDate — mag-duplicate ng row bago i-set ang bago
                    $newRow = (array) $activeService;
                    unset($newRow['PMID']);

                    $newRow['SepDate']  = Carbon::parse($fromDate)->format('m/d/Y');
                    $newRow['SepCause'] = $sepCause;
                    $newRow['FromDate'] = Carbon::parse($fromDate)->subDay()->format('Y-m-d H:i:s');
                    $newRow['ToDate']   = Carbon::parse($fromDate)->subDay()->format('Y-m-d H:i:s');

                    DB::table('xService')->insert($newRow);
                } else {
                    // Wala pang laman — direkta na lang i-update
                    DB::table('xService')
                        ->where('PMID', $activeService->PMID)
                        ->update([
                            'SepDate'  => Carbon::parse($fromDate)->format('m/d/Y'),
                            'SepCause' => $sepCause,
                        ]);
                }
                // JobOrder / Honorarium / Contractual — check muna kung may laman na ang SepDate
            } elseif (in_array($activeService->Status, ['JOB ORDER', 'JO', 'CONTRACTUAL', 'HONORARIUM'])) {

                if (!empty($activeService->SepDate)) {
                    // May laman na ang SepDate — mag-duplicate ng row bago i-set ang bago
                    $newRow = (array) $activeService;
                    unset($newRow['PMID']);

                    $newRow['SepDate']  = Carbon::parse($fromDate)->format('m/d/Y');
                    $newRow['SepCause'] = 'APPOINTED (ORIGINAL)';
                    $newRow['Renew']    = 'ORIGINAL';
                    $newRow['FromDate'] = Carbon::parse($fromDate)->subDay()->format('Y-m-d H:i:s');
                    $newRow['ToDate']   = Carbon::parse($fromDate)->subDay()->format('Y-m-d H:i:s');

                    DB::table('xService')->insert($newRow);
                } else {
                    // Wala pang laman — direkta na lang i-update
                    DB::table('xService')
                        ->where('PMID', $activeService->PMID)
                        ->update([
                            'Renew'    => 'ORIGINAL',
                            'SepCause' => 'APPOINTED (ORIGINAL)',
                            'SepDate'  => Carbon::parse($fromDate)->format('m/d/Y'),
                        ]);
                }
            }
        }

        // Move old records to history, then delete old records<|fim_middle|><|fim_middle|><|fim_middle|>
        $oldRecords = DB::table('tempRegAppointmentReorg')->where('ControlNo', $controlNo)->get();

        foreach ($oldRecords as $row) {
            TempRegHistory::create((array) $row);
        }

        DB::table('tempRegAppointmentReorg')->where('ControlNo', $controlNo)->delete();


        $designation = DB::table('yDesignation')
            ->select('Codes', 'Descriptions', 'Status')
            ->where('Descriptions', $jobPost->Position)
            ->where('Status', 'REGULAR') // always prefer Regular
            ->first();

        $office = DB::table('yOffice')
            ->where('Descriptions', $jobPost->Office)
            ->orWhere('Codes', $jobPost->Office)
            ->first();

        $officeCode = $office->Codes ?? '00000';

        $salary = DB::table('tblSalarySchedule')
            ->where('Grade', $jobPost->SalaryGrade)
            ->where('Steps', 1)
            ->first();

        $rateMon  = $salary->Salary ?? 0;
        $rateDay  = $rateMon > 0 ? $rateMon / 22 : 0;
        $rateYear = $rateMon * 12;



        //fromDate will be inputed

        $toDate = Carbon::parse('2050-12-31 00:00:00');

        $Division = DB::table('yDivision')
            ->where('Descriptions', $jobPost->Division)
            // ->orWhere('Codes', $jobPost->Division)
            ->first();

        $DivCode = $Division->Codes ?? '00000';

        $Section = DB::table('ySection')
            ->where('Descriptions', $jobPost->Section)
            // ->orWhere('Codes', $jobPost->Section)
            ->first();

        $SecCode = $Section->Codes ?? '00000';

        $Unit = DB::table('yUnit')
            ->where('Descriptions', $jobPost->Unit)
            // ->orWhere('Codes', $jobPost->Unit)
            ->first();

        $UnitCode =    $Unit->Codes ?? '00000';

        $rowsToModify = DB::table('xService')
            ->where('ControlNo', $controlNo)
            ->where('ToDate', '>', $fromDate)
            ->get();

        foreach ($rowsToModify as $row) {
            DB::table('hire_service_snapshots')->insert([
                'submission_id'   => $submissionId,
                'PMID'            => $row->PMID,
                'original_ToDate' => $row->ToDate,  // save original
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        DB::table('xService')
            ->where('ControlNo', $controlNo)
            ->where('ToDate', '>', $fromDate)
            ->update(['ToDate' => Carbon::parse($fromDate)->subDay()]); // 1

        $employeeStatus = xService::select(['ControlNo', 'FromDate', 'ToDate', 'Status'])
            ->latest('FromDate', 'ToDate')
            ->limit(1)
            ->where('ControlNo', $controlNo)
            ->first();

        $renewType = $this->determineRenewType($employeeStatus); // ← idagdag ito

        $structure = DB::table('tblStructureDetails')
            ->where('ID', $jobPost->tblStructureDetails_ID)
            ->where('PageNo', $jobPost->PageNo)
            ->where('ItemNo', $jobPost->ItemNo)
            ->first();

        DB::table('xService')->insert([
            'ControlNo'    => $controlNo, // 1
            'FromDate'     => $fromDate->format('Y-m-d H:i:s'), // 1
            'ToDate'       => $toDate->format('Y-m-d H:i:s'), // 1
            'DesigCode'    => $designation->Codes ?? '00000', // 1
            'Designation'  => $designation->Descriptions ?? $jobPost->Position, // 1
            'StatCode'     => '00001', // 1
            'Status'       => 'REGULAR',
            'OffCode'      => $officeCode ?? '00000', // 1
            'Office'       => $jobPost->Office ?? 'NONE',  // 1a
            'BranCode'     => '00001',
            'Branch'       => 'LGU-TAGUM',
            'LVRemarks'    => '',
            'RateDay'      => $rateDay, // 1
            'RateMon'      => $rateMon, // 1
            'RateYear'     => $rateYear, // 1
            'SepDate'      => '',
            'SepCause'     => '',
            'AppCode'      => '0',
            'DivCode'      => $DivCode ?? '00000', // 1
            'Divisions'    => $jobPost->Division ?? 'NONE', // 1
            'SecCode'      => $SecCode ?? '00000', // 1
            'Sections'     => $jobPost->Section ?? 'NONE', // 1
            'PlantCode'    => '', // 1
            'Renew'        => '', // 1
            'Grades'       => $jobPost->SalaryGrade ?? null, // 1
            'Steps'        => 1,
            'Charges'      => '',
            'effectiveDate'      => $fromDate->format('Y-m-d H:i:s'),
            'submission_id' => $submissionId,
            'itemNo'        => $itemNo,
            'Pages'         => $pageNo,
            'StructureID'   => $structure->StructureID ?? null,
        ]);


        //  get the renew 

        DB::table('tempRegAppointmentReorg')->insert([
            // 'ID'            => $nextId,
            'ControlNo'     => $controlNo, //1
            'DesigCode'     => $designation->Codes ?? null, //1
            'NewDesignation' => $designation->Descriptions ?? $jobPost->Position, //1
            'Designation'   => $designation->Descriptions ?? $jobPost->Position, //1
            'SG'            => $jobPost->SalaryGrade, //1
            'Step'          => 1,
            'Status'        => $designation->Status, //1
            'OffCode'       => $officeCode, //1
            'NewOffice'     => $jobPost->Office,
            'Office'        => $jobPost->Office,
            'MRate'         => $rateMon, //1
            'Official'      => 0,
            'Renew'         => $renewType,
            'ItemNo'        => $itemNo,
            'Pages'         => $pageNo,
            'StructureID'   => $structure->StructureID ?? null,
            'DivCode' => $DivCode ?? null, // 1
            'SecCode' => $SecCode ?? null, // 1
            // 'groupcode',//
            // 'group',//
            'unitcode' =>     $UnitCode ?? null,
            'unit' => $jobPost->Unit ?? null,
            'sepdate'   => $temporary_tempregReorg->sepdate
                ? Carbon::parse($temporary_tempregReorg->sepdate)->format('Y-m-d')
                : null,
            'sepcause'  => $this->upper($temporary_tempregReorg->sepcause),
            'vicename'  => $this->upper($temporary_tempregReorg->vicename),
            'vicecause' => $this->upper($temporary_tempregReorg->vicecause),
            'deliberation_date'   => $temporary_tempregReorg->deliberation_date
                ? Carbon::parse($temporary_tempregReorg->deliberation_date)->format('Y-m-d')
                : null,
            'assessment_date'   => $temporary_tempregReorg->assessment_date
                ? Carbon::parse($temporary_tempregReorg->assessment_date)->format('Y-m-d')
                : null,
            'submission_id' => $submissionId

        ]);

        // get the temRegExt
        $temPregExt = DB::table('vwPositiondescription')
            ->where('Designation', $jobPost->Position)
            ->orderBy('ID', 'desc')
            ->first();

        // qs of jobpost
        $qs = OnCriteriaJob::where('job_batches_rsp_id', $jobPost->id)->first();


        DB::table('tempRegAppointmentReorgExt')->insert([
            // 'ID'            => $nextId,
            'ControlNo'     => $temporary_tempreg_appointment_reorg_ext->ControlNo,
            'PresAppro'         =>  $temporary_tempreg_appointment_reorg_ext->PresAppro ?? null,
            'PrevAppro'         => $temporary_tempreg_appointment_reorg_ext->PrevAppro ?? null,
            'SalAuthorized'     => $temporary_tempreg_appointment_reorg_ext->SalAuthorized ?? null,
            'OtherComp'         => $temporary_tempreg_appointment_reorg_ext->OtherComp ?? null,
            'SupPosition'       => $temporary_tempreg_appointment_reorg_ext->SupPosition ?? null,
            'HSupPosition'      => $temporary_tempreg_appointment_reorg_ext->HSupPosition ?? null,
            'Tool'              => $temporary_tempreg_appointment_reorg_ext->Tool ?? null,

            'Contact1'          => $temporary_tempreg_appointment_reorg_ext->Contact1 ?? null,
            'Contact2'          => $temporary_tempreg_appointment_reorg_ext->Contact2 ?? null,
            'Contact3'          => $temporary_tempreg_appointment_reorg_ext->Contact3 ?? null,
            'Contact4'          => $temporary_tempreg_appointment_reorg_ext->Contact4 ?? null,
            'Contact5'          => $temporary_tempreg_appointment_reorg_ext->Contact5 ?? null,
            'Contact6'          => $temporary_tempreg_appointment_reorg_ext->Contact6 ?? null,
            'ContactOthers'     => $temporary_tempreg_appointment_reorg_ext->ContactOthers ?? null,

            'Working1'          => $temporary_tempreg_appointment_reorg_ext->Working1 ?? null,
            'Working2'          => $temporary_tempreg_appointment_reorg_ext->Working2 ?? null,
            'WorkingOthers'     => $temporary_tempreg_appointment_reorg_ext->WorkingOthers ?? null,

            'DescriptionSection'   => $temporary_tempreg_appointment_reorg_ext->DescriptionSection ?? null,
            'DescriptionFunction'  => $temporary_tempreg_appointment_reorg_ext->DescriptionFunction ?? null,

            'StandardEduc'      => $temporary_tempreg_appointment_reorg_ext->StandardEduc ?? null,
            'StandardExp'       => $temporary_tempreg_appointment_reorg_ext->StandardExp ?? null,
            'StandardTrain'     => $temporary_tempreg_appointment_reorg_ext->StandardTrain ?? null,
            'StandardElig'      => $temporary_tempreg_appointment_reorg_ext->StandardElig ?? null,

            'Supervisor'        => $temporary_tempreg_appointment_reorg_ext->Supervisor ?? null,

            'Core1'             => $temporary_tempreg_appointment_reorg_ext->Core1 ?? null,
            'Core2'             => $temporary_tempreg_appointment_reorg_ext->Core2 ?? null,
            'Core3'             => $temporary_tempreg_appointment_reorg_ext->Core3 ?? null,

            'Corelevel1'        => $temporary_tempreg_appointment_reorg_ext->Corelevel1 ?? null,
            'Corelevel2'        => $temporary_tempreg_appointment_reorg_ext->Corelevel2 ?? null,
            'Corelevel3'        => $temporary_tempreg_appointment_reorg_ext->Corelevel3 ?? null,
            'Corelevel4'        => $temporary_tempreg_appointment_reorg_ext->Corelevel4 ?? null,

            'Leader1'           => $temporary_tempreg_appointment_reorg_ext->Leader1 ?? null,
            'Leader2'           => $temporary_tempreg_appointment_reorg_ext->Leader2 ?? null,
            'Leader3'           => $temporary_tempreg_appointment_reorg_ext->Leader3 ?? null,
            'Leader4'           => $temporary_tempreg_appointment_reorg_ext->Leader4 ?? null,

            'leaderlevel1'      => $temporary_tempreg_appointment_reorg_ext->leaderlevel1 ?? null,
            'leaderlevel2'      => $temporary_tempreg_appointment_reorg_ext->leaderlevel2 ?? null,
            'leaderlevel3'      => $temporary_tempreg_appointment_reorg_ext->leaderlevel3 ?? null,
            'leaderlevel4'      => $temporary_tempreg_appointment_reorg_ext->leaderlevel4 ?? null,

            'StructureID'       => $temporary_tempreg_appointment_reorg_ext->StructureID ?? null,

            'submission_id' => $temporary_tempreg_appointment_reorg_ext->submission_id ?? null,
            'signing_date' => $temporary_tempreg_appointment_reorg_ext->signing_date ?? null,
        ]);


        DB::table('posting_date')->insert([
            'ControlNo'     => $controlNo, //1
            'post_date' => $jobPost->post_date,
            'end_date' => $jobPost->end_date,
            'job_batches_rsp_id' => $jobPost->id,
            'submission_id' => $submissionId
        ]);
    }

    // force Capital Letter
    private function upper($value)
    {
        return strtoupper(trim($value ?? ''));
    }

    // roll back the applicant also the jobpost
    public function rollbackHire(int $submissionId, Request $request)
    {
        DB::beginTransaction();
        try {


            $rollback = DB::table('hire_rollbacks')
                ->where('submission_id', $submissionId)
                ->first();



            if (!$rollback) {
                return response()->json([
                    'success' => false,
                    'message' => 'No rollback record found for this submission.',
                ], 404);
            }


            // return to Qualified and  false temporary hired
            Submission::where('id', $submissionId)
                ->update([
                    'status' => 'Qualified',
                    'temporary_hired' => false
                ]);

            // Rreturn to rated the job post
            JobBatchesRsp::where('id', $rollback->job_batches_rsp_id)
                ->update(['status' => 'rated']);

            $snapshots = DB::table('hire_service_snapshots')
                ->where('submission_id', $submissionId)
                ->get();

            foreach ($snapshots as $snapshot) {
                DB::table('xService')
                    ->where('PMID', $snapshot->PMID)
                    ->update(['ToDate' => $snapshot->original_ToDate]);
            }

            DB::table('hire_service_snapshots')
                ->where('submission_id', $submissionId)
                ->delete();

            // Table 1
            DB::table('xPersonal')
                ->where('ControlNo',  $rollback->controlNo)
                ->where('submission_id',  $rollback->submission_id)
                ->delete();

            DB::table('xPWD')
                ->where('ControlNo',  $rollback->controlNo)
                ->where('submission_id',  $rollback->submission_id)
                ->delete();

            DB::table('xPersonalAddt')
                ->where('ControlNo',  $rollback->controlNo)
                ->where('submission_id',  $rollback->submission_id)
                ->delete();

            DB::table('xChildren')
                ->where('ControlNo',  $rollback->controlNo)
                ->where('submission_id',  $rollback->submission_id)
                ->delete();

            DB::table('xExperience')->where('ControlNo',  $rollback->controlNo)
                ->where('submission_id',  $rollback->submission_id)
                ->delete();


            DB::table('xCivilService')->where('ControlNo',  $rollback->controlNo)
                ->where('submission_id',  $rollback->submission_id)
                ->delete();

            DB::table('xEducation')->where('ControlNo',  $rollback->controlNo)
                ->where('submission_id',  $rollback->submission_id)
                ->delete();


            DB::table('xNGO')->where('ControlNo',  $rollback->controlNo)
                ->where('submission_id',  $rollback->submission_id)
                ->delete();

            DB::table('xTrainings')->where('ControlNo',  $rollback->controlNo)
                ->where('submission_id',  $rollback->submission_id)
                ->delete();

            DB::table('xSkills')->where('ControlNo',  $rollback->controlNo)
                ->where('submission_id',  $rollback->submission_id)
                ->delete();

            DB::table('xNonAcademic')->where('ControlNo',  $rollback->controlNo)
                ->where('submission_id',  $rollback->submission_id)
                ->delete();

            DB::table('xOrganization')->where('ControlNo',  $rollback->controlNo)
                ->where('submission_id',  $rollback->submission_id)
                ->delete();

            // DB::table('xNonAcademic')->where('ControlNo',  $rollback->controlNo)
            //     ->where('submission_id',  $rollback->submission_id)
            //     ->delete();


            DB::table('posting_date')->where('ControlNo',  $rollback->controlNo)
                ->where('submission_id',  $rollback->submission_id)
                ->delete();


            // Remove the xService row created during hire
            DB::table('xService')
                ->where('ControlNo',  $rollback->controlNo)
                ->where('submission_id',  $rollback->submission_id)
                ->delete();

            // Remove tempRegAppointmentReorg entry for this hire
            DB::table('tempRegAppointmentReorg')
                ->where('ControlNo',  $rollback->controlNo)
                ->where('submission_id',  $rollback->submission_id)
                ->delete();


            // Delete rollback record (one-time use)
            DB::table('xPersonalDiversity')
                ->where('submission_id', $submissionId)
                ->delete();

            // Delete rollback record (one-time use)
            DB::table('xReference')
                ->where('submission_id', $submissionId)
                ->delete();


            // Delete rollback record (one-time use)
            DB::table('hire_rollbacks')
                ->where('submission_id', $submissionId)
                ->delete();

            DB::table('tempRegAppointmentReorgExt')
                ->where('submission_id', $submissionId)
                ->delete();


            // Activity log
            $user = Auth::user();

            if ($user instanceof \App\Models\User) {
                activity('Hire Rollback')
                    ->causedBy($user)
                    ->withProperties([
                        'name'                  => $user->name,
                        'username'              => $user->username,
                        'submission_id'         => $submissionId,
                        'control_no'            => $rollback->controlNo,
                        'prev_submission_status' => $rollback->prev_submission_status,
                        'prev_job_post_status'  => $rollback->prev_job_post_status,
                        'ip'                    => request()->ip(),
                        'user_agent'            => request()->header('User-Agent'),
                    ])
                    ->log("{$user->name} rolled back hire for submission #{$submissionId} with ControlNo {$rollback->controlNo}.");
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Hire successfully rolled back.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Rollback failed.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function determineRenewType($employeeStatus)
    {
        // External applicant — walang naunang xService record
        if (!$employeeStatus) {
            return 'ORIGINAL';
        }

        $status = strtoupper(trim($employeeStatus->Status ?? ''));

        if (in_array($status, ['JOB ORDER', 'JO', 'CASUAL', 'CONTRACTUAL', 'HONORARIUM'])) {
            return 'ORIGINAL';
        }

        if ($status === 'REGULAR') {
            return 'PROMOTION';
        }

        // fallback default kung ibang status (e.g. COTERMINOUS, ELECTIVE, atbp.)
        return '';
    }

    private function formatDate($date)
    {
        if (empty($date)) {
            return null;
        }

        try {
            return \Carbon\Carbon::createFromFormat('d/m/Y', $date)->format('Y-m-d');
        } catch (\Exception $e) {
            return null; // or throw/log if you'd rather catch bad data
        }
    }

    private function formatDateTime($date)
    {
        if (empty($date)) {
            return null;
        }

        try {
            return \Carbon\Carbon::createFromFormat('d/m/Y', $date)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
    private function parseDate($date, $outputFormat = 'Y-m-d')
    {
        if (empty($date) || strtoupper((string) $date) === 'NULL') {
            return null;
        }

        try {
            return \Carbon\Carbon::createFromFormat('d/m/Y', $date)->format($outputFormat);
        } catch (\Exception $e) {
            return null;
        }
    }

    //----------------------------------------------------------------------------------------------------------------temporary function 


    // hire an employee
    public function hireTemporaryApplicant($submissionId, $request)
    {
        // validation sa labas ng transaction para 422 ang lumabas, hindi 500
        $request->validate(
            [
                'fromDate' => 'required|date_format:Y-m-d',
            ],
            [
                'fromDate.required'    => 'The fromDate field is required. Format: YYYY-MM-DD (example: 2026-10-28).',
                'fromDate.date_format' => 'The fromDate format is invalid. Use YYYY-MM-DD (example: 2026-10-28).',
            ]

        );

        DB::beginTransaction();
        try {
            $fromDate = Carbon::parse($request->input('fromDate'));

            $submission = Submission::with('nPersonalInfo')->findOrFail($submissionId);
            $applicant  = $submission->nPersonalInfo;

            // I-check muna ang job post bago mag-insert ng anuman
            $jobPost = JobBatchesRsp::findOrFail($submission->job_batches_rsp_id);

            if ($jobPost->status === 'Occupied') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'This job post is already occupied.'
                ], 400);
            }

            if (!$applicant && !$submission->ControlNo) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Applicant personal info not found.'
                ], 404);
            }

            $existingInDB = null;

            if (!$applicant) {
                // Case 1: Already employee, walang personal info sa applicant side
                $finalControlNo = $submission->ControlNo;
                $existingInDB = DB::table('xPersonal')
                    ->where('ControlNo', $finalControlNo)
                    ->first();
            } else {
                $existingControlNo = $applicant->control_no
                    ?? $applicant->controlno
                    ?? $applicant->ControlNo
                    ?? $submission->ControlNo
                    ?? null;

                if ($existingControlNo) {
                    $existingInDB = DB::table('xPersonal')
                        ->where('ControlNo', $existingControlNo)
                        ->first();
                } else {
                    $existingInDB = DB::table('xPersonal')
                        ->where('Surname',    $applicant->lastname)
                        ->where('Firstname',  $applicant->firstname)
                        ->where('Middlename', $applicant->middlename)
                        ->first();

                    if ($existingInDB) {
                        $existingControlNo = $existingInDB->ControlNo;
                    }
                }

                $finalControlNo = $existingControlNo ?? $this->generateControlNo();
            }

            // Laging mag-save sa temporary_x_personal
            $this->insertxPersonalTemporary($applicant, $finalControlNo, $submissionId, $existingInDB);

            $jobPost->update(['status' => 'Occupied']);

            $submission->update([
                'status'          => 'Hired',
                'temporary_hired' => true,
            ]);

            $this->temporaryHirePlantilla($jobPost, $finalControlNo, $fromDate, $submissionId);

            DB::commit();

            return response()->json([
                'success'    => true,
                'message' => 'The applicant has been successfully appointed to a temporary position.',
                'control_no' => $finalControlNo,
                'job_post'   => $jobPost->id,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('hireTemporaryApplicant failed', [
                'submission_id' => $submissionId,
                'error'         => $e->getMessage(),
                'line'          => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error hiring applicant',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    private function insertxPersonalTemporary($applicant, $controlNo, $submissionId, $existing = null)
    {
        try {
            $data = [
                'ControlNo'     => $controlNo,
                'Surname'       => $existing->Surname    ?? $this->upper(optional($applicant)->lastname),
                'Firstname'     => $existing->Firstname  ?? $this->upper(optional($applicant)->firstname),
                'Middlename'    => $existing->MIddlename ?? $this->upper(optional($applicant)->middlename),
                'Sex'           => $existing->Sex        ?? $this->upper(optional($applicant)->sex),
                'BirthDate'     => $existing->BirthDate  ?? ($applicant ? $this->formatDateTime($applicant->date_of_birth) : null),
                'TINNo'         => $existing->TINNo      ?? optional($applicant)->tin_no,
                'submission_id' => $submissionId,
            ];

            Log::info('Inserting temporary_x_personal', $data);

            DB::table('temporary_x_personal')->insert($data);
        } catch (\Throwable $e) {
            Log::error('insertxPersonalTemporary failed', [
                'submission_id' => $submissionId,
                'control_no'    => $controlNo,
                'error'         => $e->getMessage(),
            ]);
            throw new \Exception('Failed to save temporary_x_personal: ' . $e->getMessage(), 0, $e);
        }
    }

    private function temporaryHirePlantilla($jobPost, $controlNo,  $fromDate, $submissionId)
    {

        $tblStructureDetails_ID = $jobPost->tblStructureDetails_ID;
        $itemNo = $jobPost->ItemNo;
        $pageNo = $jobPost->PageNo;

        $designation = DB::table('yDesignation')
            ->select('Codes', 'Descriptions', 'Status')
            ->where('Descriptions', $jobPost->Position)
            ->where('Status', 'REGULAR')
            ->first();

        $office = DB::table('yOffice')
            ->where('Descriptions', $jobPost->Office)
            ->orWhere('Codes', $jobPost->Office)
            ->first();

        $officeCode = $office->Codes ?? '00000';

        $salary = DB::table('tblSalarySchedule')
            ->where('Grade', $jobPost->SalaryGrade)
            ->where('Steps', 1)
            ->first();

        $rateMon  = $salary->Salary ?? 0;
        $rateDay  = $rateMon > 0 ? $rateMon / 22 : 0;
        $rateYear = $rateMon * 12;

        $toDate = Carbon::parse('2050-12-31 00:00:00');

        $Division = DB::table('yDivision')
            ->where('Descriptions', $jobPost->Division)

            ->first();

        $DivCode = $Division->Codes ?? '00000';

        $Section = DB::table('ySection')
            ->where('Descriptions', $jobPost->Section)

            ->first();

        $SecCode = $Section->Codes ?? '00000';

        $Unit = DB::table('yUnit')
            ->where('Descriptions', $jobPost->Unit)

            ->first();

        $UnitCode =    $Unit->Codes ?? '00000';


        $employeeStatus = xService::select(['ControlNo', 'FromDate', 'ToDate', 'Status'])
            ->latest('FromDate', 'ToDate')
            ->limit(1)
            ->where('ControlNo', $controlNo)
            ->first();

        $renewType = $this->determineRenewType($employeeStatus); // ← idagdag ito

        $structure = DB::table('tblStructureDetails')
            ->where('ID', $jobPost->tblStructureDetails_ID)
            ->where('PageNo', $jobPost->PageNo)
            ->where('ItemNo', $jobPost->ItemNo)
            ->first();

        DB::table('temporary_xservices')->insert([
            'ControlNo'    => $controlNo, // 1
            'FromDate'     => $fromDate->format('Y-m-d H:i:s'), // 1
            'ToDate'       => $toDate->format('Y-m-d H:i:s'), // 1
            'DesigCode'    => $designation->Codes ?? '00000', // 1
            'Designation'  => $designation->Descriptions ?? $jobPost->Position, // 1
            'StatCode'     => '00001', // 1
            'Status'       => 'REGULAR',
            'OffCode'      => $officeCode ?? '00000', // 1
            'Office'       => $jobPost->Office ?? 'NONE',  // 1a
            'BranCode'     => '00001',
            'Branch'       => 'LGU-TAGUM',
            'LVRemarks'    => '',
            'RateDay'      => $rateDay, // 1
            'RateMon'      => $rateMon, // 1
            'RateYear'     => $rateYear, // 1
            'SepDate'      => '',
            'SepCause'     => '',
            'AppCode'      => '0',
            'DivCode'      => $DivCode ?? '00000', // 1
            'Divisions'    => $jobPost->Division ?? 'NONE', // 1
            'SecCode'      => $SecCode ?? '00000', // 1
            'Sections'     => $jobPost->Section ?? 'NONE', // 1
            'PlantCode'    => '', // 1
            'Renew'        => '', // 1
            'Grades'       => $jobPost->SalaryGrade ?? null, // 1
            'Steps'        => 1,
            'Charges'      => '',
            'effectiveDate'      => $fromDate->format('Y-m-d H:i:s'),
            'submission_id' => $submissionId,
            'itemNo'        => $itemNo,
            'Pages'         => $pageNo,
            'StructureID'   => $structure->StructureID ?? null,
        ]);


        //  get the renew 

        DB::table('temporary_tempreg_appointment_reorg')->insert([
            // 'ID'            => $nextId,
            'ControlNo'     => $controlNo, //1
            'DesigCode'     => $designation->Codes ?? null, //1
            'NewDesignation' => $designation->Descriptions ?? $jobPost->Position, //1
            'Designation'   => $designation->Descriptions ?? $jobPost->Position, //1
            'SG'            => $jobPost->SalaryGrade, //1
            'Step'          => 1,
            'Status'        => $designation->Status, //1
            'OffCode'       => $officeCode, //1
            'NewOffice'     => $jobPost->Office,
            'Office'        => $jobPost->Office,
            'MRate'         => $rateMon, //1
            'Official'      => 0,
            'Renew'         => $renewType,
            'ItemNo'        => $itemNo,
            'Pages'         => $pageNo,
            'StructureID'   => $structure->StructureID ?? null,
            'DivCode' => $DivCode ?? null, // 1
            'SecCode' => $SecCode ?? null, // 1
            // 'groupcode',//
            // 'group',//
            'unitcode' =>     $UnitCode ?? null,
            'unit' => $jobPost->Unit ?? null,
            'sepdate' => ' ',
            'sepcause' =>  ' ',
            'vicename' =>  ' ',
            'vicecause' =>  ' ',
            'submission_id' => $submissionId

        ]);

        // get the temRegExt
        $temPregExt = DB::table('vwPositiondescription')
            ->where('Designation', $jobPost->Position)
            ->orderBy('ID', 'desc')
            ->first();

        // qs of jobpost
        $qs = OnCriteriaJob::where('job_batches_rsp_id', $jobPost->id)->first();


        DB::table('temporary_tempreg_appointment_reorg_ext')->insert([
            // 'ID'            => $nextId,
            'ControlNo'     => $controlNo,
            'PresAppro'         =>  $temPregExt->PresAppro ?? null,
            'PrevAppro'         => $temPregExt->PrevAppro ?? null,
            'SalAuthorized'     => $rateMon !== null ? number_format($rateMon, 2) . ' / mo.' : null,
            'OtherComp'         => $temPregExt->OtherComp ?? null,
            'SupPosition'       => null,
            'HSupPosition'      => null,
            'Tool'              => $temPregExt->Tool ?? null,

            'Contact1'          => $temPregExt->Contact1 ?? null,
            'Contact2'          => $temPregExt->Contact2 ?? null,
            'Contact3'          => $temPregExt->Contact3 ?? null,
            'Contact4'          => $temPregExt->Contact4 ?? null,
            'Contact5'          => $temPregExt->Contact5 ?? null,
            'Contact6'          => $temPregExt->Contact6 ?? null,
            'ContactOthers'     => $temPregExt->ContactOthers ?? null,

            'Working1'          => $temPregExt->Working1 ?? null,
            'Working2'          => $temPregExt->Working2 ?? null,
            'WorkingOthers'     => $temPregExt->WorkingOthers ?? null,

            'DescriptionSection'   => $temPregExt->DescriptionSection ?? null,
            'DescriptionFunction'  => $temPregExt->DescriptionFunction ?? null,

            'StandardEduc'      => $qs->Education ?? null,
            'StandardExp'       => $qs->Experience ?? null,
            'StandardTrain'     => $qs->Training ?? null,
            'StandardElig'      => $qs->Eligibility ?? null,

            'Supervisor'        => null,

            'Core1'             => $temPregExt->Core1 ?? null,
            'Core2'             => $temPregExt->Core2 ?? null,
            'Core3'             => $temPregExt->Core3 ?? null,

            'Corelevel1'        => $temPregExt->Corelevel1 ?? null,
            'Corelevel2'        => $temPregExt->Corelevel2 ?? null,
            'Corelevel3'        => $temPregExt->Corelevel3 ?? null,
            'Corelevel4'        => $temPregExt->Corelevel4 ?? null,

            'Leader1'           => $temPregExt->Leader1 ?? null,
            'Leader2'           => $temPregExt->Leader2 ?? null,
            'Leader3'           => $temPregExt->Leader3 ?? null,
            'Leader4'           => $temPregExt->Leader4 ?? null,

            'leaderlevel1'      => $temPregExt->leaderlevel1 ?? null,
            'leaderlevel2'      => $temPregExt->leaderlevel2 ?? null,
            'leaderlevel3'      => $temPregExt->leaderlevel3 ?? null,
            'leaderlevel4'      => $temPregExt->leaderlevel4 ?? null,

            'StructureID'       => $structure->StructureID ?? null,

            'submission_id' => $submissionId

        ]);


        DB::table('temporary_posting_dates')->insert([
            'ControlNo'     => $controlNo,
            'post_date' => $jobPost->post_date,
            'end_date' => $jobPost->end_date,
            'job_batches_rsp_id' => $jobPost->id,
            'submission_id' => $submissionId
        ]);
    }


    public function hireTemporaryRevert(int $submissionId): void
    {
        DB::transaction(function () use ($submissionId) {
            $submission = Submission::findOrFail($submissionId);
            $jobPost    = JobBatchesRsp::findOrFail($submission->job_batches_rsp_id);



            $jobPost->update(['status' => 'rated']);

            $submission->update([
                'status'          => 'Qualified',
                'temporary_hired' => false,
            ]);

            $tables = [
                'temporary_xservices',
                'temporary_tempreg_appointment_reorg',
                'temporary_tempreg_appointment_reorg_ext',
                'temporary_posting_dates', // verify: 'posting_date' ba ang tama?
                'temporary_x_personal',

            ];

            foreach ($tables as $table) {
                DB::table($table)->where('submission_id', $submissionId)->delete();
            }
        });
    }

    public function listOfTemporaryApplicantHired()
    {
        return DB::table('submission as s')
            ->join('temporary_xservices as x', 'x.submission_id', '=', 's.id')
            ->leftJoin('temporary_x_personal as p', 'p.submission_id', '=', 's.id')
            ->leftJoin('xPersonal as xp', 'xp.ControlNo', '=', 'x.ControlNo')
            ->where('s.status', 'Hired')
            ->where('s.temporary_hired', true)
            ->select([
                's.id as submission_id',
                'x.ControlNo',
                DB::raw('COALESCE(p.Surname, xp.Surname) as Surname'),
                DB::raw('COALESCE(p.Firstname, xp.Firstname) as Firstname'),
                DB::raw('COALESCE(p.Middlename, xp.Middlename) as Middlename'),
                'x.Designation',
                'x.Office',
                'x.FromDate',
                'x.RateMon',
                'x.Pages',
                'x.ItemNo',
            ])
            ->get();
    }
}
