<?php

namespace App\Services;

use Carbon\Carbon;
use App\Mail\EmailApi;
use App\Models\Submission;
use Illuminate\Http\Request;
use App\Models\JobBatchesRsp;
use App\Models\TempRegHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;


class ApplicantHiringService


{
     // hire an employee
    public function hireApplicant($submissionId,$request)
    {


        DB::beginTransaction();
        try {
            // These methods exist on Illuminate\Http\Request
            $request->validate([

                // service
                'SepDate' => 'nullable|date',
                'SepCause' => 'nullable|string|max:255',

                // temprog
                'sepdate' => 'nullable|date',
                'sepcause' => 'nullable|string|max:255',
                'vicename' => 'nullable|string|max:255',
                'vicecause' => 'nullable|string|max:255',

                //appoitment affective
                'fromDate' => 'required|date_format:Y-m-d'

            ]);

            // appiotment date
            $fromDate = Carbon::parse($request->input('fromDate'));


            // Then pass them explicitly
            $SepDate_service  = $request->input('SepDate');
            $SepCause_service = $request->input('SepCause');


            $sepdate = $request->input('sepdate');
            $sepcause = $request->input('sepcause');
            $vicename = $request->input('vicename');
            $vicecause = $request->input('vicecause');



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
                'nPersonalInfo.personal_declarations'
            ])->findOrFail($submissionId);

            $applicant = $submission->nPersonalInfo;

            // Case 1: Already employee
            if (!$applicant && $submission->ControlNo) {
                $finalControlNo = $submission->ControlNo;
            } else {
                if (!$applicant) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Applicant personal info not found.'
                    ], 404);
                }

                $family = $applicant->family;

                $personal_declarations = $applicant->personal_declarations->first();

                $existingControlNo = $applicant->control_no ?? $applicant->controlno ?? $applicant->ControlNo ?? $submission->ControlNo ?? null;

                // ✅ If no ControlNo on model, check xPersonal by name before generating
                if (!$existingControlNo) {
                    $existingInDB = DB::table('xPersonal')
                        ->where('Surname',    $applicant->lastname)
                        ->where('Firstname',  $applicant->firstname)
                        ->where('Middlename', $applicant->middlename)
                        ->first();

                    if ($existingInDB) {
                        // Reuse existing ControlNo — skip all inserts
                        $existingControlNo = $existingInDB->ControlNo;
                    }
                }

                $finalControlNo = $existingControlNo ?? $this->generateControlNo();

                $alreadyInXPersonal = DB::table('xPersonal')
                    ->where('ControlNo', $finalControlNo)
                    ->exists();

                // $existingControlNo
                if (!$alreadyInXPersonal) {
                    $this->insertPersonalInfo($applicant, $family,  $personal_declarations, $finalControlNo, $submissionId);
                    $this->insertxPersonalAddt($applicant,$family, $personal_declarations, $finalControlNo, $submissionId);
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

            // Update job post and submission status
            $jobPost = JobBatchesRsp::findOrFail($submission->job_batches_rsp_id);

            $prevSubmissionStatus = $submission->status;
            $prevJobPostStatus    = $jobPost->status;

            if ($jobPost->status === 'Occupied') {
                return response()->json([
                    'success' => false,
                    'message' => 'This job post is already occupied.'
                ], 400);
            }

            $jobPost->update(['status' => 'Occupied']);
            $submission->update(['status' => 'Hired']);

            // Update plantilla structure
            // $SepDate_service  = $request->input('SepDate');
            // $SepCause_service = $request->input('SepCause');
            $this->updatePlantillaStructure($jobPost, $finalControlNo, $SepDate_service, $SepCause_service,$sepdate, $sepcause, $vicename, $vicecause, $fromDate, $submissionId);

            // ✅ Send email notification to the hired applicant
            $externalApplicant = DB::table('xPersonalAddt')
                ->join('xPersonal', 'xPersonalAddt.ControlNo', '=', 'xPersonal.ControlNo')
                ->where('xPersonalAddt.ControlNo', $submission->ControlNo)
                ->select('xPersonalAddt.*', 'xPersonal.Firstname', 'xPersonal.Surname', 'xPersonalAddt.EmailAdd')
                ->first();

            $activeApplicant = $applicant ?? $externalApplicant;



            $user = Auth::user();

            if ($user && $user instanceof \App\Models\User) {

                $position = $jobPost->Position ?? $jobPost->position ?? 'Unknown Position';

                activity($user->username)
                    ->causedBy($user)
                    ->performedOn($submission)
                    ->withProperties([
                        'username' => $user->username,
                        'office' => $user->office,
                        'job_position'      => $position,
                        'hired_control_no'  => $finalControlNo,
                        'submission_id'     => $submission->id,
                        'ip_address'        => $request->ip(),
                        'user_agent'        => $request->header('User-Agent'),
                    ])
                    ->log("{$user->name} hired applicant (ControlNo: {$finalControlNo}) for job post {$jobPost->id}.");
            }

            // save the for the rollback just in case
            DB::table('hire_rollbacks')->insert([
                'controlNo'  => $finalControlNo,
                'submission_id'        => $submissionId,
                'job_batches_rsp_id'   => $jobPost->id,
                'prev_submission_status'  => $prevSubmissionStatus,
                'prev_job_post_status'    => $prevJobPostStatus,

                'expired_at'           => Carbon::now()->addDays(3),
                'created_at'           => Carbon::now(),
                'updated_at'           => Carbon::now(),
            ]);


            DB::commit();

            return response()->json([
                'success'    => true,
                'message'    => 'Applicant hired successfully, plantilla updated, and email sent.',
                'control_no' => $finalControlNo,
                'job_post'   => $jobPost->id,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error hiring applicant',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    private function generateControlNo()
    {
        $maxControlNo = DB::table('xPersonal')->max('ControlNo');
        $nextNumber   = $maxControlNo ? intval($maxControlNo) + 1 : 1;
        return str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
    }

    private function insertPersonalInfo($applicant, $family,  $personal_declarations, $controlNo,$submissionId)
    {
        DB::table('xPersonal')->insert([
            'ControlNo'    => $controlNo,
            'Surname'      => $this->upper($applicant->lastname),
            'Firstname'    =>  $this->upper($applicant->firstname),
            'Middlename'   =>  $this->upper($applicant->middlename),
            'Sex'          =>  $this->upper($applicant->sex),
            'CivilStatus'  => $this->upper($applicant->civil_status),
            'BirthDate'    => $this->upper($applicant->date_of_birth),
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
            'Pics' => $applicant->image_path ?? null,
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




            // 'R1' =>  $personal_declarations->{'response_34'} ?? null,

            // 'Q2' =>  $personal_declarations->{'question_34b'}  ?? null,
            // 'R2' =>  $personal_declarations->{'response_34'} ?? null,
            // 'Q22' =>  $personal_declarations->{'question_40c'} ?? null,
        ]);
    }

    private function insertPWD( $personal_declarations, $controlNo, $submissionId)
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

    private function insertxPersonalAddt($applicant,$family,$personal_declarations, $controlNo, $submissionId)
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
            'Rpurok'      =>$this->upper( $applicant->Rpurok),
            'Rsubdivision'      =>  $this->upper($applicant->residential_subdivision),
            'Rbarangay'      =>  $this->upper($applicant->residential_barangay),
            'Rprovince'      =>  $this->upper($applicant->residential_province),
            'Rregion'      =>  $this->upper($applicant->residential_region),
            'Rcity'      =>  $this->upper($applicant->residential_city),
            'Rzip'      =>  $applicant->residential_zip ?? '',

            'Pregion'      => $this->upper($applicant->permanent_region),
            'Phouse'      =>$this->upper($applicant->permanent_house),
            'Ppurok'      => $this->upper($applicant->Ppurok),
            'Pstreet'      => $this->upper($applicant->permanent_street),
            'Psubdivision'      => $this->upper($applicant->permanent_subdivision ),
            'Pbarangay'      => $this->upper($applicant->permanent_barangay),
            'Pcity'      =>$this->upper( $applicant->permanent_city),
            'Pprovince'      => $this->upper($applicant->permanent_province),
            'Pzip'      => $applicant->permanent_zip ?? '',

            'local'      => 0,

            'localdetails'      => '',
            'country'      => 0,

            'countrydetails'      => '',
            'datefiled'      => '',
            'gender'      =>$this->upper( $applicant->gender_prefer),
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
                'BirthDate' => $child->birth_date,
                'submission_id' => $submissionId
            ]);
        }
    }

    private function insertWorkExperience($experiences, $controlNo, $submissionId)
    {
        foreach ($experiences ?? [] as $exp) {
            DB::table('xExperience')->insert([
                'CONTROLNO'  => $controlNo,
                'WFrom'      => $exp->work_date_from,
                'WTo'        => $exp->work_date_to,
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
                'DateFrom'    => $work->inclusive_date_from,
                'DateTo'      => $work->inclusive_date_to,
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
                'DateFrom'    => $train->inclusive_date_from ?? null,
                'DateTo'      => $train->inclusive_date_to ?? null,
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

    private function updatePlantillaStructure($jobPost, $controlNo, $SepDate_service, $SepCause_service,$sepdate, $sepcause, $vicename, $vicecause, $fromDate, $submissionId)
    {

        $tblStructureDetails_ID = $jobPost->tblStructureDetails_ID;
        $itemNo = $jobPost->ItemNo;
        $pageNo = $jobPost->PageNo;


        $activeService = DB::table('xService')
            ->where('ControlNo', $controlNo)   // ensure active record
            ->orderBy('PMID', 'DESC')
            ->first();

        // ✅ Temporarily log to see what's coming in
        // Log::info('updatePlantillaStructure called', [
        //     'controlNo'        => $controlNo,
        //     'SepDate_service'  => $SepDate_service,
        //     'SepCause_service' => $SepCause_service,
        //     'activeService'    => $activeService,
        // ]);

        // 2. If employee has active service, SepDate and SepCause are required
        if ($activeService) {

            // if (empty($SepDate_service) || empty($SepCause_service)) {
            //     throw new \Exception("SepDate and SepCause are required when re-appointing an active employee.");
            // }

            DB::table('xService')
                ->where('PMID', $activeService->PMID)
                ->update([
                    'SepDate'  => Carbon::parse($SepDate_service)->format('Y-m-d'),
                    'SepCause' =>  $SepCause_service,
                ]);
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

        $toDate   = $fromDate->copy()->addYears(50);

        $Division = DB::table('yDivision')
            ->where('Descriptions', $jobPost->Division)
            ->orWhere('Codes', $jobPost->Division)
            ->first();

        $DivCode = $Division->Codes ?? '00000';

        $Section = DB::table('ySection')
            ->where('Descriptions', $jobPost->Section)
            ->orWhere('Codes', $jobPost->Section)
            ->first();

        $SecCode = $Section->Codes ?? '00000';

        $Unit = DB::table('yUnit')
            ->where('Descriptions', $jobPost->Unit)
            ->orWhere('Codes', $jobPost->Unit)
            ->first();

        $UnitCode =    $Unit->Codes ?? '00000';

        DB::table('xService')
            ->where('ControlNo', $controlNo)
            ->where('ToDate', '>', $fromDate)
            ->update(['ToDate' => Carbon::parse($fromDate)->subDay()]);



        DB::table('xService')->insert([
            'ControlNo'    => $controlNo, // 1
            'FromDate'     => $fromDate->format('Y-m-d H:i:s'), // 1
            'ToDate'       => $toDate->format('Y-m-d H:i:s'), // 1
            'DesigCode'    => $designation->Codes ?? '00000', // 1
            'Designation'  => $designation->Descriptions ?? $jobPost->Position, // 1
            'StatCode'     => '00001', // 1
            'Status'       => 'REGULAR',
            'OffCode'      => $officeCode ?? '00000', // 1
            'Office'       => $jobPost->Office ?? 'NONE',  // 1
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
            'Divisions'    => $jobPost->Division ?? null, // 1
            'SecCode'      => $SecCode ?? null, // 1
            'Sections'     => $jobPost->Section ?? null, // 1
            'PlantCode'    => $jobPost->PlantCode ?? null, // 1
            'Renew'        => $jobPost->Renew ?? null, // 1
            'Grades'       => $jobPost->SalaryGrade ?? null, // 1
            'Steps'        => 1,
            'Charges'      => '',
            'effectiveDate'      => $fromDate->format('Y-m-d H:i:s'),
            'submission_id' => $submissionId
        ]);

        $structure = DB::table('tblStructureDetails')
            ->where('ID', $jobPost->tblStructureDetails_ID)
            ->where('PageNo', $jobPost->PageNo)
            ->where('ItemNo', $jobPost->ItemNo)
            ->first();

        // $nextId = DB::table('tempRegAppointmentReorg')->max('ID') + 1;

        DB::table('tempRegAppointmentReorg')->insert([
            // 'ID'            => $nextId,
            'ControlNo'     => $controlNo,//1
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
            'Renew'         => 'REAPPOINTMENT PURSUANT TO RA6656',
            'ItemNo'        => $itemNo,
            'Pages'         => $pageNo,
            'StructureID'   => $structure->StructureID ?? null,
            'DivCode' => $DivCode ?? null, // 1
            'SecCode' => $SecCode ?? null, // 1
            // 'groupcode',//
            // 'group',//
            'unitcode' =>     $UnitCode ?? null,
            'unit' => $jobPost->Unit ?? null,
            'sepdate' =>  $this->upper($sepdate),
            'sepcause' =>  $this->upper($sepcause),
            'vicename' =>  $this->upper($vicename),
            'vicecause' =>  $this->upper($vicecause),
            'submission_id' => $submissionId

        ]);


        DB::table('posting_date')->insert([
            'ControlNo'     => $controlNo, //1
            'post_date' =>$jobPost->post_date,
            'end_date' => $jobPost->end_date,
            'job_batches_rsp_id' =>$jobPost->id,
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

            if (Carbon::now()->isAfter($rollback->expired_at)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rollback window has expired (3 days).',
                ], 403);
            }

            // Restore submission status
            Submission::where('id', $submissionId)
                ->update(['status' => $rollback->prev_submission_status]);

            // Restore job post status
            JobBatchesRsp::where('id', $rollback->job_batches_rsp_id)
                ->update(['status' => $rollback->prev_job_post_status]);

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

            DB::table('xNonAcademic')->where('ControlNo',  $rollback->controlNo)
                ->where('submission_id',  $rollback->submission_id)
                ->delete();


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
            DB::table('hire_rollbacks')
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
}
