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
            'nPersonalInfo:id,firstname,lastname',
            'xpersonal:ControlNo,Surname,Firstname',
            'job_batch_rsp:id,Position,Office'
        ])->where('status','Qualified')
            ->whereDoesntHave('scheduleApplicants')
            ->get()
            ->map(function ($item) {

                // 👇 Determine Name Source
                if ($item->nPersonalInfo_id) {
                    // Outside applicant
                    $firstname = optional($item->nPersonalInfo)->firstname;
                    $lastname  = optional($item->nPersonalInfo)->lastname;
                } else {
                    // Employee → get from xpersonal
                    $firstname = optional($item->xpersonal)->Firstname;
                    $lastname  = optional($item->xpersonal)->Surname;
                }

                return [
                    "submission_id"         => $item->id,
                    "nPersonalInfo_id"      => $item->nPersonalInfo_id,
                    "ControlNo"             => $item->ControlNo,
                    "job_batches_rsp_id"    => $item->job_batches_rsp_id,

                    // Final selected fullname
                    "firstname"             => $firstname,
                    "lastname"              => $lastname,

                    "job_batch_rsp"         => [
                        "job_batches_rsp_id" => $item->job_batch_rsp->id ?? null,
                        "Office"           => $item->job_batch_rsp->Office ?? null,
                        "Position"           => $item->job_batch_rsp->Position ?? null,
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


    public function getApplicantInterview($scheduleId)  // fetch applicant belong on the interview schedules
    {
        $schedule = Schedule::with([
            'scheduleApplicants.submission.nPersonalInfo',
            'scheduleApplicants.submission.xPersonal',
            'scheduleApplicants.submission.job_batch_rsp',
        ])->findOrFail($scheduleId);

        $applicants = $schedule->scheduleApplicants->map(function ($sa) {

            $submission = $sa->submission;
            if (!$submission) {
                return null;
            }

            $fullname  = null;
            $cellphone = null;

            // ✅ INTERNAL APPLICANT
            if ($submission->nPersonalInfo) {
                $fullname = trim(
                    $submission->nPersonalInfo->firstname . ' ' .
                        $submission->nPersonalInfo->lastname
                );

                $cellphone = $submission->nPersonalInfo->cellphone_number ?? null;
            }

            // ✅ EXTERNAL APPLICANT (fallback)
            if (!$fullname && $submission->xPersonal) {
                $fullname = trim(
                    $submission->xPersonal->Firstname . ' ' .
                        $submission->xPersonal->Surname
                );
            }

            // ✅ EXTERNAL CONTACT (fallback)
            if (!$cellphone && $submission->ControlNo) {
                $cellphone = DB::table('xPersonalAddt')
                    ->where('ControlNo', $submission->ControlNo)
                    ->value('CellphoneNo');
            }

            return [
                'applicant_name' => $fullname,
                'contact_no'     => $cellphone,
                'position'       => $submission->job_batch_rsp->Position ?? null,
            ];
        })->filter()->values();

        return response()->json([
            'schedule_id' => $schedule->id,
            'batch_name'  => $schedule->batch_name,
            'date'        => $schedule->date_interview,
            'time'        => $schedule->time_interview,
            'venue'       => $schedule->venue_interview,
            'applicants'  => $applicants
        ]);
    }



    // ---------------------------------------------------------------interview end---------------------------------------------------------//



    // ---------------------------------------------------------------examination---------------------------------------------------------//


    // applicant dont have yet schedule for examination
    public function applicantListExam()
    {
        $applicants = Submission::with([
            'nPersonalInfo:id,firstname,lastname',
            'xpersonal:ControlNo,Surname,Firstname',
            'job_batch_rsp:id,Position,Office'
        ])->where('status', 'Qualified')
            ->whereDoesntHave('SchedulesExamApplicant')
            ->get()
            ->map(function ($item) {

                // 👇 Determine Name Source
                if ($item->nPersonalInfo_id) {
                    // Outside applicant
                    $firstname = optional($item->nPersonalInfo)->firstname;
                    $lastname  = optional($item->nPersonalInfo)->lastname;
                } else {
                    // Employee → get from xpersonal
                    $firstname = optional($item->xpersonal)->Firstname;
                    $lastname  = optional($item->xpersonal)->Surname;
                }

                return [
                    "submission_id"         => $item->id,
                    "nPersonalInfo_id"      => $item->nPersonalInfo_id,
                    "ControlNo"             => $item->ControlNo,
                    "job_batches_rsp_id"    => $item->job_batches_rsp_id,

                    // Final selected fullname
                    "firstname"             => $firstname,
                    "lastname"              => $lastname,

                    "job_batch_rsp"         => [
                        "job_batches_rsp_id" => $item->job_batch_rsp->id ?? null,
                        "Office"           => $item->job_batch_rsp->Office ?? null,
                        "Position"           => $item->job_batch_rsp->Position ?? null,
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




    public function getApplicantExamination($examinationScheduleId)  // fetch applicant belong on the interview schedules
    {
        $schedule = SchedulesExam::with([
            'scheduleExamApplicants.submission.nPersonalInfo',
            'scheduleExamApplicants.submission.xPersonal',
            'scheduleExamApplicants.submission.job_batch_rsp',
        ])->findOrFail($examinationScheduleId);

        $applicants = $schedule->scheduleExamApplicants->map(function ($sa) {

            $submission = $sa->submission;
            if (!$submission) {
                return null;
            }

            $fullname  = null;
            $cellphone = null;

            // ✅ INTERNAL APPLICANT
            if ($submission->nPersonalInfo) {
                $fullname = trim(
                    $submission->nPersonalInfo->firstname . ' ' .
                        $submission->nPersonalInfo->lastname
                );

                $cellphone = $submission->nPersonalInfo->cellphone_number ?? null;
            }

            // ✅ EXTERNAL APPLICANT (fallback)
            if (!$fullname && $submission->xPersonal) {
                $fullname = trim(
                    $submission->xPersonal->Firstname . ' ' .
                        $submission->xPersonal->Surname
                );
            }

            // ✅ EXTERNAL CONTACT (fallback)
            if (!$cellphone && $submission->ControlNo) {
                $cellphone = DB::table('xPersonalAddt')
                    ->where('ControlNo', $submission->ControlNo)
                    ->value('CellphoneNo');
            }

            return [
                'applicant_name' => $fullname,
                'contact_no'     => $cellphone,
                'position'       => $submission->job_batch_rsp->Position ?? null,
            ];
        })->filter()->values();

        return response()->json([
            'schedule_id' => $schedule->id,
            'batch_name'  => $schedule->batch_name,
            'date'        => $schedule->date_interview,
            'time'        => $schedule->time_interview,
            'venue'       => $schedule->venue_interview,
            'applicants'  => $applicants
        ]);
    }

    // ---------------------------------------------------------------examination end--------------------------------------------------------//
}
