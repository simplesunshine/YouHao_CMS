<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LottoDltRecommendation extends Model
{
    protected $table = 'lotto_dlt_recommendations';

    protected $fillable = [
        'front_numbers',
        'back_numbers',
        'username',
    ];
}
