<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LottoSsqRecommendation extends Model
{
    protected $table = 'lotto_ssq_recommendations';

    protected $fillable = [
        'front_numbers',
        'back_numbers',
        'username',
    ];
}
