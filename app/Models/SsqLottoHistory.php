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
        'zone_ratio',
        'red_cold_json',
    ];

    public $timestamps = false;

    protected static function boot()
    {
        parent::boot();

        static::saving(function (self $model) {

            // 1️⃣ 收集红球
            $fronts = [
                (int)$model->front1,
                (int)$model->front2,
                (int)$model->front3,
                (int)$model->front4,
                (int)$model->front5,
                (int)$model->front6,
            ];

            sort($fronts);

            // 2️⃣ front_numbers / back_numbers
            $model->front_numbers = implode(',', $fronts);
            $model->back_numbers  = (string)(int)$model->back;

            // 3️⃣ 和值 / 跨度
            $model->front_sum = array_sum($fronts);
            $model->span      = max($fronts) - min($fronts);

            // 4️⃣ 奇偶统计
            $odd = 0;
            foreach ($fronts as $n) {
                if ($n % 2 === 1) {
                    $odd++;
                }
            }
            $model->odd_count  = $odd;
            $model->even_count = 6 - $odd;

            // 5️⃣ 区间比（1-11 / 12-22 / 23-33）
            $zones = [0, 0, 0];
            foreach ($fronts as $n) {
                if ($n <= 11) {
                    $zones[0]++;
                } elseif ($n <= 22) {
                    $zones[1]++;
                } else {
                    $zones[2]++;
                }
            }
            $model->zone_ratio = implode(',', $zones);

            // ❗ red_cold_json 不在这里算
            // 因为这里还没有 id（新建时）
        });
    }
}
