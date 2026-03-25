<?php

namespace App\Models\excel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Personal_declarations extends Model
{
    use HasFactory;
    protected $table = 'personal_declarations';

    protected $fillable = [
        // Q34
        'nPersonalInfo_id',

        'question_34a',

        'question_34b',
        'response_34', //reason

        // Q35
        'question_35a',
        'response_35a', //reason

        'question_35b',
        'response_35b_date', //reason
        'response_35b_status', //reason

        // Q36
        'question_36',
        'response_36', //reason

        // Q37
        'question_37',
        'response_37', //reason

        // Q38
        'question_38a',
        'response_38a', //reason

        'question_38b',
        'response_38b', //reason

        // Q39
        'question_39',
        'response_39', //reason

        // Q40
        'question_40a',
        'response_40a', //reason

        'question_40b',
        'response_40b', //reason

        'question_40c',
        'response_40c', //reason
    ];
    // Relationship to nPersonalInfo
    public function personalInfo()
    {
        return $this->belongsTo(nPersonal_info::class, 'nPersonalInfo_id');
    }
    protected static function newFactory()
    {
        return \Database\Factories\PersonalDeclarationsFactory::new();
    }
}
