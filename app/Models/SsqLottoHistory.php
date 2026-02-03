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
        'front_sum',
        'span',
        'weights',
        'front_numbers',
        'back_numbers',
        'odd_count',
        'even_count',
        'zone_ratio'
    ];

    public $timestamps = false;

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {

            $fronts = [
                (int)$model->front1,
                (int)$model->front2,
                (int)$model->front3,
                (int)$model->front4,
                (int)$model->front5,
                (int)$model->front6,
            ];

            // 红球和值
            $model->front_sum = array_sum($fronts);

            // 跨度
            $model->span = max($fronts) - min($fronts);
        });
    }
}
