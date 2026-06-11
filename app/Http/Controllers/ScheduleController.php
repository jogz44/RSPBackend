<?php

namespace App\Http\Controllers;


use App\Models\Schedule;
use App\Models\SchedulesExam;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class ScheduleController extends Controller
{

    // ---------------------------------------------------------------interview---------------------------------------------------------//
    // applicant list  without schedule
    // need to add pagination
    public function applicantList()
    {
        $applicants = Submission::with([
            'nPersonalInfo:id,firstname,lastname,residential_street,residential_barangay,residential_city,residential_province,Rpurok',
            'xPersonal:ControlNo,Surname,Firstname',
            'xPersonalAddt:ControlNo,Rpurok,Rstreet,Rbarangay,Rcity,Rprovince',
            'job_batch_rsp:id,Position,Office,ItemNo,PageNo'
        ])->where('status', 'Qualified')
            ->whereDoesntHave('scheduleApplicants')
            ->get()
            ->map(function ($item) {

                // ── Name + Address ───────────────────────────────────────────
                if ($item->nPersonalInfo_id) {
                    // EXTERNAL applicant
                    $firstname = optional($item->nPersonalInfo)->firstname;
                    $lastname  = optional($item->nPersonalInfo)->lastname;
                    $purok     = optional($item->nPersonalInfo)->Rpurok               ?? null;
                    $street    = optional($item->nPersonalInfo)->residential_street   ?? null;
                    $barangay  = optional($item->nPersonalInfo)->residential_barangay ?? null;
                    $city      = optional($item->nPersonalInfo)->residential_city     ?? null;
                    $province  = optional($item->nPersonalInfo)->residential_province ?? null;
                } else {
                    // INTERNAL applicant
                    $firstname = optional($item->xPersonal)->Firstname;
                    $lastname  = optional($item->xPersonal)->Surname;
                    $purok     = optional($item->xPersonalAddt)->Rpurok    ?? null;
                    $street    = optional($item->xPersonalAddt)->Rstreet   ?? null;
                    $barangay  = optional($item->xPersonalAddt)->Rbarangay ?? null;
                    $city      = optional($item->xPersonalAddt)->Rcity     ?? null;
                    $province  = optional($item->xPersonalAddt)->Rprovince ?? null;
                }

                return [
                    'submission_id'      => $item->id,
                    'nPersonalInfo_id'   => $item->nPersonalInfo_id,
                    'ControlNo'          => $item->ControlNo,
                    'job_batches_rsp_id' => $item->job_batches_rsp_id,

                    'firstname'          => $firstname,
                    'lastname'           => $lastname,

                    // ── address fields ──
                    'purok'              => $purok,
                    'street'             => $street,
                    'barangay'           => $barangay,
                    'city'               => $city,
                    'province'           => $province,

                    'job_batch_rsp' => [
                        'job_batches_rsp_id' => $item->job_batch_rsp->id       ?? null,
                        'Office'             => $item->job_batch_rsp->Office   ?? null,
                        'Position'           => $item->job_batch_rsp->Position ?? null,
                        'ItemNo'             => $item->job_batch_rsp->ItemNo   ?? null,
                        'PageNo'             => $item->job_batch_rsp->PageNo   ?? null,
                    ],
                ];
            });

        return response()->json($applicants);
    }

    // fetching Schedule
    public function fetchScheduleInterview(Request $request)
    {
        $search  = $request->input('search');
        $perPage = $request->input('per_page', 10);

        $query = Schedule::query()
            ->withCount('scheduleApplicants');

        // 🔍 Search
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('batch_name', 'like', "%{$search}%")
                    ->orWhere('venue_interview', 'like', "%{$search}%");
            });
        }

        $schedules = $query->paginate($perPage);

        // 🧹 Transform after pagination
        $schedules->getCollection()->transform(function ($schedule) {
            return [
                'schedule_id'     => $schedule->id,
                'batch_name'      => $schedule->batch_name,
                'venue_interview' => $schedule->venue_interview,
                'date_interview'  => $schedule->date_interview,
                'time_interview'  => $schedule->time_interview,
                'applicant_no'    => $schedule->schedule_applicants_count,
            ];
        });

        return response()->json($schedules);
    }


    // public function getApplicantInterview($scheduleId)  // fetch applicant belong on the interview schedules
    // {
    //     $schedule = Schedule::with([
    //         'scheduleApplicants.submission.nPersonalInfo',
    //         'scheduleApplicants.submission.xPersonal',
    //         'scheduleApplicants.submission.xPersonalAddt',
    //         'scheduleApplicants.submission.job_batch_rsp',
    //     ])->findOrFail($scheduleId);

    //     $applicants = $schedule->scheduleApplicants->map(function ($sa) {

    //         $submission = $sa->submission;
    //         if (!$submission) {
    //             return null;
    //         }

    //         $fullname  = null;
    //         $cellphone = null;

    //         // ✅ INTERNAL APPLICANT
    //         if ($submission->nPersonalInfo) {
    //             $fullname = trim(
    //                 $submission->nPersonalInfo->firstname . ' ' .
    //                     $submission->nPersonalInfo->lastname

    //             );
    //                       $submission->nPersonalInfo->residential_street 
    //                        $submission->nPersonalInfo->residential_barangay 
    //                         $submission->nPersonalInfo->residential_city 
    //                          $submission->nPersonalInfo->residential_province 
    //                             $submission->nPersonalInfo->Rpurok 

    //             $cellphone = $submission->nPersonalInfo->cellphone_number ?? null;
    //         }

    //         // ✅ EXTERNAL APPLICANT (fallback)
    //         if (!$fullname && $submission->xPersonal) {
    //             $fullname = trim(
    //                 $submission->xPersonal->Firstname . ' ' .
    //                     $submission->xPersonal->Surname

    //             );
    //             // address
    //                        $submission->xPersonalAddt->Rbarangay    
    //                           $submission->xPersonalAddt->Rcity
    //                              $submission->xPersonalAddt->Rpurok
    //                                  $submission->xPersonalAddt->Rprovince
    //                                               $submission->xPersonalAddt->Rstreet
    //         }

    //         // ✅ EXTERNAL CONTACT (fallback)
    //         if (!$cellphone && $submission->ControlNo) {
    //             $cellphone = DB::table('xPersonalAddt')
    //                 ->where('ControlNo', $submission->ControlNo)
    //                 ->value('CellphoneNo');
    //         }

    //         return [
    //             'applicant_name' => $fullname,
    //             'contact_no'     => $cellphone,
    //             'position'       => $submission->job_batch_rsp->Position ?? null,
    //             'pageNo'       => $submission->job_batch_rsp->PageNo ?? null,
    //             'itemNo'       => $submission->job_batch_rsp->ItemNo ?? null,
    //             'office'       => $submission->job_batch_rsp->Office ?? null,
    //         ];
    //     })->filter()->values();

    //     return response()->json([
    //         'schedule_id' => $schedule->id,
    //         'batch_name'  => $schedule->batch_name,
    //         'date'        => $schedule->date_interview,
    //         'time'        => $schedule->time_interview,
    //         'venue'       => $schedule->venue_interview,
    //         'applicants'  => $applicants
    //     ]);
    // }

    public function getApplicantInterview($scheduleId)
    {
        $schedule = Schedule::with([
            'scheduleApplicants.submission.nPersonalInfo',
            'scheduleApplicants.submission.xPersonal',
            'scheduleApplicants.submission.xPersonalAddt',
            'scheduleApplicants.submission.job_batch_rsp',
        ])->findOrFail($scheduleId);

        $applicants = $schedule->scheduleApplicants->map(function ($sa) {

            $submission = $sa->submission;
            if (!$submission) return null;

            $fullname  = null;
            $cellphone = null;
            $purok     = null;
            $street    = null;
            $barangay  = null;
            $city      = null;
            $province  = null;

            // ✅ EXTERNAL APPLICANT
            if ($submission->nPersonalInfo) {
                $fullname  = trim(
                    $submission->nPersonalInfo->firstname . ' ' .
                        $submission->nPersonalInfo->lastname
                );
                $cellphone = $submission->nPersonalInfo->cellphone_number ?? null;
                $purok     = $submission->nPersonalInfo->Rpurok               ?? null;
                $street    = $submission->nPersonalInfo->residential_street   ?? null;
                $barangay  = $submission->nPersonalInfo->residential_barangay ?? null;
                $city      = $submission->nPersonalInfo->residential_city     ?? null;
                $province  = $submission->nPersonalInfo->residential_province ?? null;
            }

            // ✅ INTERNAL APPLICANT (fallback)
            if (!$fullname && $submission->xPersonal) {
                $fullname  = trim(
                    $submission->xPersonal->Firstname . ' ' .
                        $submission->xPersonal->Surname
                );
                $purok    = $submission->xPersonalAddt->Rpurok    ?? null;
                $street   = $submission->xPersonalAddt->Rstreet   ?? null;
                $barangay = $submission->xPersonalAddt->Rbarangay ?? null;
                $city     = $submission->xPersonalAddt->Rcity     ?? null;
                $province = $submission->xPersonalAddt->Rprovince ?? null;
            }

            // ✅ INTERNAL CONTACT (fallback)
            if (!$cellphone && $submission->ControlNo) {
                $cellphone = DB::table('xPersonalAddt')
                    ->where('ControlNo', $submission->ControlNo)
                    ->value('CellphoneNo');
            }

            return [
                'applicant_name' => $fullname,
                'contact_no'     => $cellphone,
                'purok'          => $purok,
                'street'         => $street,
                'barangay'       => $barangay,
                'city'           => $city,
                'province'       => $province,
                'position'       => $submission->job_batch_rsp->Position ?? null,
                'pageNo'         => $submission->job_batch_rsp->PageNo   ?? null,
                'itemNo'         => $submission->job_batch_rsp->ItemNo   ?? null,
                'office'         => $submission->job_batch_rsp->Office   ?? null,
            ];
        })->filter()->values();

        return response()->json([
            'schedule_id' => $schedule->id,
            'batch_name'  => $schedule->batch_name,
            'date'        => $schedule->date_interview,
            'time'        => $schedule->time_interview,
            'venue'       => $schedule->venue_interview,
            'applicants'  => $applicants,
        ]);
    }


    // ---------------------------------------------------------------interview end---------------------------------------------------------//



    // ---------------------------------------------------------------examination---------------------------------------------------------//


    // applicant dont have yet schedule for examination
   public function applicantListExam()
{
    $applicants = Submission::with([
        'nPersonalInfo:id,firstname,lastname,residential_street,residential_barangay,residential_city,residential_province,Rpurok',
        'xPersonal:ControlNo,Surname,Firstname',
        'xPersonalAddt:ControlNo,Rpurok,Rstreet,Rbarangay,Rcity,Rprovince',
        'job_batch_rsp:id,Position,Office,ItemNo,PageNo'
    ])->where('status', 'Qualified')
        ->whereDoesntHave('SchedulesExamApplicant')
        ->get()
        ->map(function ($item) {

            // ── Name + Address ───────────────────────────────────────────
            if ($item->nPersonalInfo_id) {
                // EXTERNAL applicant
                $firstname = optional($item->nPersonalInfo)->firstname;
                $lastname  = optional($item->nPersonalInfo)->lastname;
                $purok     = optional($item->nPersonalInfo)->Rpurok               ?? null;
                $street    = optional($item->nPersonalInfo)->residential_street   ?? null;
                $barangay  = optional($item->nPersonalInfo)->residential_barangay ?? null;
                $city      = optional($item->nPersonalInfo)->residential_city     ?? null;
                $province  = optional($item->nPersonalInfo)->residential_province ?? null;
            } else {
                // INTERNAL applicant
                $firstname = optional($item->xPersonal)->Firstname;
                $lastname  = optional($item->xPersonal)->Surname;
                $purok     = optional($item->xPersonalAddt)->Rpurok    ?? null;
                $street    = optional($item->xPersonalAddt)->Rstreet   ?? null;
                $barangay  = optional($item->xPersonalAddt)->Rbarangay ?? null;
                $city      = optional($item->xPersonalAddt)->Rcity     ?? null;
                $province  = optional($item->xPersonalAddt)->Rprovince ?? null;
            }

            return [
                'submission_id'      => $item->id,
                'nPersonalInfo_id'   => $item->nPersonalInfo_id,
                'ControlNo'          => $item->ControlNo,
                'job_batches_rsp_id' => $item->job_batches_rsp_id,

                'firstname'          => $firstname,
                'lastname'           => $lastname,

                // ── address fields ──
                'purok'              => $purok,
                'street'             => $street,
                'barangay'           => $barangay,
                'city'               => $city,
                'province'           => $province,

                'job_batch_rsp' => [
                    'job_batches_rsp_id' => $item->job_batch_rsp->id       ?? null,
                    'Office'             => $item->job_batch_rsp->Office   ?? null,
                    'Position'           => $item->job_batch_rsp->Position ?? null,
                    'ItemNo'             => $item->job_batch_rsp->ItemNo   ?? null,
                    'PageNo'             => $item->job_batch_rsp->PageNo   ?? null,
                ],
            ];
        });

    return response()->json($applicants);
}



    // fetching Schedule
    public function fetchScheduleExamination(Request $request)
    {
        $search  = $request->input('search');
        $perPage = $request->input('per_page', 10);

        $query = SchedulesExam::query()
            ->withCount('scheduleExamApplicants');

        // 🔍 Search
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('batch_name', 'like', "%{$search}%")
                    ->orWhere('venue_exam', 'like', "%{$search}%");
            });
        }

        $schedules_exam = $query->paginate($perPage);

        // 🧹 Transform after pagination
        $schedules_exam->getCollection()->transform(function ($schedules_exam) {
            return [
                'schedule_id'     => $schedules_exam->id,
                'batch_name'      => $schedules_exam->batch_name,
                'venue_exam' => $schedules_exam->venue_exam,
                'date_exam'  => $schedules_exam->date_exam,
                'time_exam'  => $schedules_exam->time_exam,
                'applicant_no'    => $schedules_exam->schedule_exam_applicants_count,
            ];
        });

        return response()->json($schedules_exam);
    }




    public function getApplicantExamination($examinationScheduleId)
    {
        $schedule = SchedulesExam::with([
            'scheduleExamApplicants.submission.nPersonalInfo',
            'scheduleExamApplicants.submission.xPersonal',
            'scheduleExamApplicants.submission.xPersonalAddt',
            'scheduleExamApplicants.submission.job_batch_rsp',
        ])->findOrFail($examinationScheduleId);

        $applicants = $schedule->scheduleExamApplicants->map(function ($sa) {

            $submission = $sa->submission;
            if (!$submission) return null;

            $fullname  = null;
            $cellphone = null;
            $purok     = null;
            $street    = null;
            $barangay  = null;
            $city      = null;
            $province  = null;

            // ✅ EXTERNAL APPLICANT
            if ($submission->nPersonalInfo) {
                $fullname  = trim(
                    $submission->nPersonalInfo->firstname . ' ' .
                        $submission->nPersonalInfo->lastname
                );
                $cellphone = $submission->nPersonalInfo->cellphone_number    ?? null;
                $purok     = $submission->nPersonalInfo->Rpurok               ?? null;
                $street    = $submission->nPersonalInfo->residential_street   ?? null;
                $barangay  = $submission->nPersonalInfo->residential_barangay ?? null;
                $city      = $submission->nPersonalInfo->residential_city     ?? null;
                $province  = $submission->nPersonalInfo->residential_province ?? null;
            }

            // ✅ INTERNAL APPLICANT (fallback)
            if (!$fullname && $submission->xPersonal) {
                $fullname = trim(
                    $submission->xPersonal->Firstname . ' ' .
                        $submission->xPersonal->Surname
                );

                $addt     = $submission->xPersonalAddt
                    ?? DB::table('xPersonalAddt')
                    ->where('ControlNo', $submission->ControlNo)
                    ->select('Rpurok', 'Rstreet', 'Rbarangay', 'Rcity', 'Rprovince')
                    ->first();

                $purok    = $addt->Rpurok    ?? null;
                $street   = $addt->Rstreet   ?? null;
                $barangay = $addt->Rbarangay ?? null;
                $city     = $addt->Rcity     ?? null;
                $province = $addt->Rprovince ?? null;
            }

            // ✅ INTERNAL CONTACT (fallback)
            if (!$cellphone && $submission->ControlNo) {
                $cellphone = DB::table('xPersonalAddt')
                    ->where('ControlNo', $submission->ControlNo)
                    ->value('CellphoneNo');
            }

            return [
                'applicant_name' => $fullname,
                'contact_no'     => $cellphone,
                'purok'          => $purok,
                'street'         => $street,
                'barangay'       => $barangay,
                'city'           => $city,
                'province'       => $province,
                'position'       => $submission->job_batch_rsp->Position ?? null,
                'pageNo'         => $submission->job_batch_rsp->PageNo   ?? null,
                'itemNo'         => $submission->job_batch_rsp->ItemNo   ?? null,
                'office'         => $submission->job_batch_rsp->Office   ?? null,
            ];
        })->filter()->values();

        return response()->json([
            'schedule_id' => $schedule->id,
            'batch_name'  => $schedule->batch_name,
            'date'        => $schedule->date_exam,
            'time'        => $schedule->time_exam,
            'venue'       => $schedule->venue_exam,
            'applicants'  => $applicants,
        ]);
    }

    // ---------------------------------------------------------------examination end--------------------------------------------------------//
}
