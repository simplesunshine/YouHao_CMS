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
        'weights',
        'red_cold_json',
        'zone_ratio',
        'odd_count',
        'even_count'
    ];

    public $timestamps = false;

    protected static function boot()
    {
        parent::boot();

        // -------------------------
        // 保存前计算字段
        // -------------------------
        static::saving(function (self $model) {

            // 前区号码
            $fronts = [
                (int)$model->front1,
                (int)$model->front2,
                (int)$model->front3,
                (int)$model->front4,
                (int)$model->front5,
            ];

            sort($fronts);

            // 前区和值 / 跨度
            $model->front_sum = array_sum($fronts);
            $model->span      = max($fronts) - min($fronts);

            // 前区奇偶统计
            $odd = count(array_filter($fronts, fn($n) => $n % 2 === 1));
            $model->odd_count  = $odd;
            $model->even_count = count($fronts) - $odd;

            // 前区区间比（1-12 / 13-24 / 25-35）
            $zones = [0,0,0];
            foreach ($fronts as $n) {
                if ($n >= 1 && $n <= 12)      $zones[0]++;
                elseif ($n >= 13 && $n <= 24) $zones[1]++;
                else                           $zones[2]++;
            }
            $model->zone_ratio = implode(',', $zones);

            // 前区号码拼接字符串
            $model->front_numbers = implode(',', $fronts);

            // 后区号码拼接字符串
            $model->back_numbers = implode(',', [(int)$model->back1, (int)$model->back2]);

            // ❗ red_cold_json 不在这里算，因为新建时还没有 id
        });

        // -------------------------
        // 保存后计算 red_cold_json
        // -------------------------
        static::saved(function (self $model) {

            $currentId = $model->id;
            $cold = [];

            // 遍历 1~35 红球
            for ($n = 1; $n <= 35; $n++) {
                // 查最近出现期号
                $lastId = \DB::table($model->getTable())
                    ->whereRaw('? IN (front1,front2,front3,front4,front5)', [$n])
                    ->max('id');

                $cold[$n] = $lastId ? ($currentId - $lastId) : $currentId;
            }

            $model->red_cold_json = json_encode($cold, JSON_UNESCAPED_UNICODE);

        });
    }

}
