<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LotterySetting extends Model
{
    protected $table = 'lottery_settings';

    protected $fillable = [
        'type',
        'issue',
        'enabled',
    ];
}
