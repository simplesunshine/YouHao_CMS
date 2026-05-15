<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LotterySetting extends Model
{
    protected $table = 'lottery_settings';

    // 建议加上这个，这样查询时会自动关联子表数据
    protected $with = ['strategyItems'];

    protected $fillable = ['type', 'issue', 'enabled'];

    public function strategyItems() {
        // 5.7 建议写全类名，防止找不到类
        return $this->hasMany('App\Models\LotteryStrategyItem', 'setting_id');
    }
}