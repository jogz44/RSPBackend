<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulesExamApplicant extends Model
{
    //
    protected $table = 'schedules_exam_applicants';


    protected $fillable = [
        'schedule_id',
        'submission_id'
    ];

    public function submission()
    {
        return $this->belongsTo(Submission::class, 'submission_id');
    }
}
