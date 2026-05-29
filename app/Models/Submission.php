<?php

namespace App\Models;

use App\Models\excel\nFamily;
use App\Models\excel\Children;
use Illuminate\Support\Facades\DB;
use App\Models\excel\nPersonal_info;
use App\Models\excel\Work_experience;
use Illuminate\Database\Eloquent\Model;
use App\Models\excel\Education_background;
use App\Models\excel\Learning_development;
use App\Models\excel\Civil_service_eligibity;
use Illuminate\Database\Eloquent\Relations\Pivot;

// class Submission extends Pivot
class Submission extends Model
{
    //
    protected $dates = []; // ✅ override default date casting — prevent Carbon on all date columns
    protected $table = 'submission'; // applicant apply on the job post
    protected $fillable = [
        'nPersonalInfo_id', // applicant
        'job_batches_rsp_id',

        'education_remark',
        'experience_remark',
        'training_remark',
        'eligibility_remark',
        'status',
        'ControlNo',

        'education_qualification',
        'experience_qualification',
        'training_qualification',
        'eligibility_qualification',


        'exam_details',
        'exam_type',
        'exam_total_score',
        'exam_date',
        'exam_remarks',
        'is_emailed'

    ];

    protected $casts = [
        'education_qualification' => 'array',
        'experience_qualification' => 'array',
        'training_qualification' => 'array',
        'eligibility_qualification' => 'array',
        'created_at'               => 'string', // ✅ prevent Carbon hydration
        'updated_at'               => 'string', // ✅ prevent Carbon hydration
        'exam_date'                => 'string', // ✅ prevent Carbon hydration
    ];

    public $timestamps = true; // or just remove if not set

    public function nPersonalInfo()
    {
        return $this->belongsTo(nPersonal_info::class, 'nPersonalInfo_id');
    }
    public function ControlNo() // external applicants
    {
        return $this->belongsTo(xPersonal::class, 'ControlNo', 'ControlNo');
    }

    public function xPersonal() // external applicants
    {
        return $this->belongsTo(xPersonal::class, 'ControlNo', 'ControlNo');
    }

    public function xPersonalAddt() // external applicants
    {
        return $this->belongsTo(xPersonalAddt::class, 'ControlNo', 'ControlNo');
    }


    public function jobPost()
    {
        return $this->belongsTo(JobBatchesRsp::class, 'job_batches_rsp_id');
    }

    public function job_batch_rsp()
    {
        return $this->belongsTo(JobBatchesRsp::class, 'job_batches_rsp_id', 'id');
    }

    // ----- interview ---- //
    public function schedules()
    {
        return $this->hasMany(Schedule::class, 'submission_id', 'id');
    }

    public function scheduleApplicants()
    {
        return $this->hasMany(SchedulesApplicant::class, 'submission_id', 'id');
    }
    // ----- interview ---- //


    // ----- examination ---- //
    public function schedulesExam()
    {
        return $this->hasMany(SchedulesExam::class, 'submission_id', 'id');
    }

    public function SchedulesExamApplicant()
    {
        return $this->hasMany(SchedulesExamApplicant::class, 'submission_id', 'id');
    }
    // ----- examination ---- //







    // external record applicant

    // ✅ NEW: Get education records by IDs
    public function getEducationRecordsExternal() // getEducationRecordsExternal
    {
        if (empty($this->education_qualification)) {
            return collect();
        }

        return Education_background::whereIn('id', $this->education_qualification)->get();
    }

    // ✅ NEW: Get experience records by IDs
    public function getExperienceRecordsExternal() //getExperienceRecordsExternal
    {
        if (empty($this->experience_qualification)) {
            return collect();
        }

        return Work_experience::whereIn('id', $this->experience_qualification)->get();
    }

    // ✅ NEW: Get training records by IDs
    public function getTrainingRecordsExternal() //getTrainingRecordsExternal
    {
        if (empty($this->training_qualification)) {
            return collect();
        }

        return Learning_development::whereIn('id', $this->training_qualification)->get();
    }

    // ✅ NEW: Get eligibility records by IDs
    public function getEligibilityRecordsExternal() //getEligibilityRecordsExternal
    {
        if (empty($this->eligibility_qualification)) {
            return collect();
        }

        return Civil_service_eligibity::whereIn('id', $this->eligibility_qualification)->get();
    }



    // Internal records applicant

    // ✅ NEW: Get education records by IDs
    public function getEducationRecordsInternal() //getEducationRecordsInternal
    {
        if (empty($this->education_qualification)) {
            return collect();
        }

        return DB::table('xEducation')->whereIn('PMID', $this->education_qualification)->get(); //Education_background
    }

    // ✅ NEW: Get experience records by IDs`
    // public function getExperienceRecordsInternal() //getExperienceRecordsInternal
    // {
    //     if (empty($this->experience_qualification)) {
    //         return collect();
    //     }

    //     return  DB::table('xExperience')->whereIn('ID', $this->experience_qualification)->get(); //Work_experience
    //     // return  DB::table('xService')->whereIn('PMID', $this->experience_qualification)->get(); //Work_experience
    // }
    public function getExperienceRecordsInternal($controlNo)
    {
        // ✅ Fetch from xService
        $serviceRecords = DB::table('xService')
            ->where('ControlNo', $controlNo)
            ->select('PMID as id', 'ControlNo', 'FromDate as WFrom', 'ToDate as WTo','Designation as WPosition','Office as WCompany')
            ->orderBy('FromDate')
            ->get();

        // ✅ Find the latest record (by ToDate, then FromDate)
        $latestServiceId = $serviceRecords
            ->sortByDesc(fn($row) => [$row->WTo, $row->WFrom])
            ->first()?->id;

        // ✅ Normalize dates, cap future ToDate on latest record to today
        $service = $serviceRecords->map(function ($row) use ($latestServiceId) {
            $isLatest = $row->id === $latestServiceId;

            $toDate = $row->WTo ? \Carbon\Carbon::parse($row->WTo) : null;

            // Cap future-dated WTo to today only on the latest record
            if ($isLatest && $toDate && $toDate->isFuture()) {
                $toDate = \Carbon\Carbon::today();
            }

            $row->WFrom = $row->WFrom
                ? \Carbon\Carbon::parse($row->WFrom)->format('m/d/Y')
                : null;

            $row->WTo = $toDate
                ? $toDate->format('m/d/Y')
                : null;

            $row->experience_status = 'SERVICE';
            return $row;
        });

        // ✅ Fetch from xExperience (private/external experience records)
        $experienceRecords = DB::table('xExperience')
            ->whereIn('ID', $this->experience_qualification ?? [])
            ->get()
            ->map(function ($record) {
                // ✅ Normalize WFrom
                $record->WFrom = ($record->WFrom && strtoupper(trim($record->WFrom)) !== 'CURRENT')
                    ? \Carbon\Carbon::parse($record->WFrom)->format('m/d/Y')
                    : null;

                // ✅ Normalize WTo — treat "CURRENT" as today
                $wTo = strtoupper(trim($record->WTo ?? ''));
                $record->WTo = ($wTo === 'CURRENT' || $wTo === '')
                    ? \Carbon\Carbon::now()->format('m/d/Y')  // treat as present
                    : \Carbon\Carbon::parse($record->WTo)->format('m/d/Y');

                $record->experience_status = 'EXPERIENCE';
                return $record;
            });
        // ✅ Merge both collections into one
        return $serviceRecords->merge($experienceRecords);
    }


    // ✅ NEW: Get training records by IDs
    public function getTrainingRecordsInternal() //getTrainingRecordsInternal
    {
        if (empty($this->training_qualification)) {
            return collect();
        }

        return DB::table('xTrainings')->whereIn('PMID', $this->training_qualification)->get();
    }

    // ✅ NEW: Get eligibility records by IDs
    public function getEligibilityRecordsInternal() // getEligibilityRecordsInternal
    {
        if (empty($this->eligibility_qualification)) {
            return collect();
        }

        return DB::table('xCivilService')->whereIn('PMID', $this->eligibility_qualification)->get(); //Civil_service_eligibity
    }

    public function applicantExamScore()
    {
        return $this->hasOne(ApplicantExamScore::class, 'submission_id', 'id');
    }
}
