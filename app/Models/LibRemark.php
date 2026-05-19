<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LibRemark extends Model
{
    //
    use HasFactory;

    protected $table = 'lib_remarks';

    protected $fillable = [

            'remarks',
            'category'
    ];
}
