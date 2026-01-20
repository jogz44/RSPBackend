<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    use HasFactory;

    protected  $table = 'activity_logs';
    protected $fillable = [
        'user_id',
        'username',
        'actions',
        'position',
        'date_performed',
        'user_agent',
        'ip_address'
    ];
}
