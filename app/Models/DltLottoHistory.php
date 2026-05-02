<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DltLottoHistory extends Model
{
    protected $table = 'dlt_lotto_history';

    // 允许批量赋值的字段
    protected $fillable = [
        'issue', 'front1','front2','front3','front4','front5',
        'back1','back2', 'front_sum', 'span', 'weights',
        'zone_ratio', 'odd_count', 'even_count', 'front_numbers', 'back_numbers'
    ];

    public $timestamps = false;
    
    // 移除 boot 方法中的所有 saving/saved 逻辑，保持模型“干净”
}
