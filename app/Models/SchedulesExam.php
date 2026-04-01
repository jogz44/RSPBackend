<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulesExam extends Model
{
    //
    protected  $table = 'schedules_exams';
    protected $fillable = [
        'batch_name',
        'date_exam',
        'time_exam',
        'venue_exam'
    ];



    public function scheduleExamApplicants()
    {
        return $this->hasMany(SchedulesExamApplicant::class, 'schedule_id');
    }

}
