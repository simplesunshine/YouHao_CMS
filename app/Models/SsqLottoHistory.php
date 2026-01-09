<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SsqLottoHistory extends Model
{
    protected $table = 'ssq_lotto_history';

    protected $fillable = [
        'issue',
        'front1','front2','front3','front4','front5','front6',
        'back',
    ];

    public $timestamps = false;
}
