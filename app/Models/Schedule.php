<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    //
    protected  $table = 'schedules';
    protected $fillable = [
        'batch_name',
        'date_interview',
        'time_interview',
        'venue_interview'
    ];
    public function submission()
    {
        return $this->belongsTo(Submission::class, 'submission_id');
    }

    public function scheduleApplicants()
    {
        return $this->hasMany(SchedulesApplicant::class, 'schedule_id');
    }


    public function job_batch_rsp()
    {
        return $this->belongsTo(JobBatchesRsp::class, 'job_batches_rsp_id');
    }
}
