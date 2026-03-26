<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulesExam extends Model
{
    //
    protected  $table = 'schedules_exams';
    protected $fillable = [
        'batch_name',
        'date_interview',
        'time_interview',
        'venue_interview'
    ];

    public function scheduleExamApplicants()
    {
        return $this->hasMany(SchedulesExamApplicant::class, 'schedule_id');
    }

}
