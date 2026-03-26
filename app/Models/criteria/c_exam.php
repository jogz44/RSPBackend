<?php

namespace App\Models\criteria;

use Illuminate\Database\Eloquent\Model;

class c_exam extends Model
{
    //


    protected $table = 'c_exams';

    protected $fillable = [
        'criteria_rating_id',
        'weight',
        'description',
        'percentage'


    ];

    public function criteriaRating()
    {
        return $this->belongsTo(criteria_rating::class, 'criteria_rating_id');
    }
}
