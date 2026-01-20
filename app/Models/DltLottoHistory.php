<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DltLottoHistory extends Model
{
    protected $table = 'dlt_lotto_history';

    protected $fillable = [
        'issue',

        // 前区
        'front1','front2','front3','front4','front5',

        // 后区
        'back1','back2',

        // 派生字段
        'front_sum',
        'span',
        'weights'
    ];

    public $timestamps = false;

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {

            // 前区号码
            $fronts = [
                (int)$model->front1,
                (int)$model->front2,
                (int)$model->front3,
                (int)$model->front4,
                (int)$model->front5,
            ];

            // 前区和值
            $model->front_sum = array_sum($fronts);

            // 前区跨度（最大 - 最小）
            $model->span = max($fronts) - min($fronts);
        });
    }
}
