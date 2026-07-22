<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeReAssign extends Model
{
    //

    protected $table = 'employee_reassigns';


    protected $fillable = [
        'control_no',
        'office',
        'office2',
        'group',
        'division',
        'section',
        'unit',
        're_assign_date',
        'active'
    ];

  
}
