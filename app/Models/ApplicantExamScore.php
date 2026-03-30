<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicantExamScore extends Model
{
    //

    protected $table = 'applicant_exam_scores';


    protected $fillable = [
              'submission_id',
              'exam_score',
              'exam_details',
              'exam_type',
              'exam_total_score',
              'exam_date',
              'exam_remarks'

    ];


    public function submission()
    {
        return $this->belongsTo(Submission::class, 'submission_id');
    }


}
