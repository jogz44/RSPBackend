<?php

namespace App\Models;

use App\Models\excel\nPersonal_info;
use Illuminate\Database\Eloquent\Model;

class EmployeeAssign extends Model
{
    //

    protected $table = 'employee_assigns';


    protected $fillable = [
        'control_no',
        'office',
        'office2',
        'group',
        'division',
        'section',
        'unit',


    ];

    public function employeeReAssign()
    {
        return $this->hasMany(EmployeeReAssign::class, 'control_no', 'control_no');
    }

    public function xPersonal()
    {
        return $this->hasOne(xPersonal::class, 'ControlNo', 'control_no');
    }

    public function vwActive()
    {
        return $this->hasOne(vwActive::class, 'ControlNo', 'control_no');
    }
}
