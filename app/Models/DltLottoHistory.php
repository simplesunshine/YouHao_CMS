<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DltLottoHistory extends Model
{
    protected $table = 'dlt_lotto_history';

    protected $fillable = [
        'issue',
        'front1','front2','front3','front4','front5',
        'back1','back2',
    ];

    public $timestamps = false;
}
