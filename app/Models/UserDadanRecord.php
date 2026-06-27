<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserDadanRecord extends Model
{
    // 指定关联的表名
    protected $table = 'user_dadan_records';

    // 允许批量赋值的字段
    protected $fillable = [
        'user_id',
        'username',
        'lottery_type',
        'issue',
        'numbers',
        'ball_count',
        'affected_rows',
        'ip',
    ];
}