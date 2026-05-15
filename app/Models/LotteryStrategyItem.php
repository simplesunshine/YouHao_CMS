<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LotteryStrategyItem extends Model
{
    // 删掉 use HasFactory; 这一行

    protected $table = 'lottery_strategy_items';

    protected $fillable = [
        'setting_id',
        'label',
        'content',
        'rule_type',
        'sort_order',
    ];

    // 如果表里没加 created_at 和 updated_at，必须关掉
    public $timestamps = false; 

    public function setting()
    {
        return $this->belongsTo('App\Models\LotterySetting', 'setting_id');
    }
}