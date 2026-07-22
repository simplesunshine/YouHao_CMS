<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SsqLottoHistory extends Model
{
    protected $table = 'ssq_lotto_history';

    protected $fillable = [
        'issue', 'front1','front2','front3','front4','front5','front6',
        'back', 'front_sum', 'span', 'weights', 'front_numbers',
        'back_numbers', 'odd_count', 'even_count', 'zone_ratio', 'red_cold_json',
        'score'
    ];

    public $timestamps = false;
    
    // 彻底移除 boot 方法，避免逻辑冲突
}