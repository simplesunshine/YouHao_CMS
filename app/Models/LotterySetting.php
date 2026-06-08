<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class LotterySetting extends Model
{
    protected $table = 'lottery_settings';

    // 查询时自动关联子表数据
    protected $with = ['strategyItems'];

    protected $fillable = ['type', 'issue', 'enabled', 'summary', 'result_note'];

    public function strategyItems() {
        return $this->hasMany('App\Models\LotteryStrategyItem', 'setting_id');
    }
    
    /**
     * 兼容 Laravel 5.7 的初始化方法 (注意：方法名是 boot，不是 booted)
     */
    protected static function boot()
    {
        // 关键：Laravel 5.7 必须手动执行父类的 boot() 方法
        parent::boot(); 

        // 绑定模型保存成功后的事件
        static::saved(function ($lotterySetting) {
            
            // 只有启用状态才执行
            if ($lotterySetting->enabled != 1) {
                return;
            }

            $lotteryType = '';
            $killRedBalls = [];
            $killBlueBalls = [];

            // 辅助解析函数：兼容逗号、空格分隔的开奖号码串
            $parseNumbers = function($str) {
                if (!$str) return [];
                $parts = preg_split('/[,\s]+/', trim($str));
                return array_filter(array_map('intval', $parts));
            };

            // =================================================================
            // 1. 双色球算法逻辑 (type == 1)
            // =================================================================
            if ($lotterySetting->type == 1) {
                $lotteryType = 'ssq';

                // ---- 核心一：近 20 期冷号过滤 (出现次数 < 3) ----
                $history20 = DB::table('ssq_lotto_history')->orderBy('issue', 'desc')->limit(20)->get();
                
                $redStats20  = array_fill(1, 33, 0);
                $blueStats20 = array_fill(1, 16, 0);

                foreach ($history20 as $row) {
                    $reds  = isset($row->front) ? $parseNumbers($row->front) : array_filter([(int)($row->front1??0),(int)($row->front2??0),(int)($row->front3??0),(int)($row->front4??0),(int)($row->front5??0),(int)($row->front6??0)]);
                    $blues = isset($row->back) ? $parseNumbers($row->back) : array_filter([(int)($row->back1??0)]);

                    foreach ($reds as $num) { if (isset($redStats20[$num])) $redStats20[$num]++; }
                    foreach ($blues as $num) { if (isset($blueStats20[$num])) $blueStats20[$num]++; }
                }

                foreach ($redStats20 as $num => $count) { if ($count < 3) $killRedBalls[] = $num; }
                foreach ($blueStats20 as $num => $count) { if ($count < 3) $killBlueBalls[] = $num; }

                // ---- 核心二：近 5 期热号过滤 (出现次数 > 3) ----
                $history5 = DB::table('ssq_lotto_history')
                    ->select(['front1', 'front2', 'front3', 'front4', 'front5', 'front6'])
                    ->orderBy('issue', 'desc')
                    ->limit(5)
                    ->get();

                $redStats5 = [];
                foreach ($history5 as $row) {
                    foreach (['front1', 'front2', 'front3', 'front4', 'front5', 'front6'] as $field) {
                        $num = intval($row->$field);
                        if ($num > 0) { $redStats5[$num] = ($redStats5[$num] ?? 0) + 1; }
                    }
                }
                foreach ($redStats5 as $num => $count) {
                    if ($count > 3 && !in_array($num, $killRedBalls)) { $killRedBalls[] = $num; }
                }
            } 
            
            // =================================================================
            // 2. 大乐透算法逻辑 (type == 2)
            // =================================================================
            elseif ($lotterySetting->type == 2) {
                $lotteryType = 'dlt';

                // ---- 核心一：近 40 期冷号过滤 (出现次数 < 4) ----
                $history40 = DB::table('dlt_lotto_history')->orderBy('issue', 'desc')->limit(40)->get();
                
                $redStats40  = array_fill(1, 35, 0);
                $blueStats40 = array_fill(1, 12, 0);

                foreach ($history40 as $row) {
                    $reds = []; $blues = [];
                    if (isset($row->front)) {
                        $reds  = array_filter(array_map('intval', explode(',', $row->front)));
                        $blues = array_filter(array_map('intval', explode(',', $row->back)));
                    } else {
                        $reds = [(int)($row->front1 ?? 0), (int)($row->front2 ?? 0), (int)($row->front3 ?? 0), (int)($row->front4 ?? 0), (int)($row->front5 ?? 0)];
                        $blues = [(int)($row->back1 ?? 0), (int)($row->back2 ?? 0)];
                    }

                    foreach ($reds as $num) { if ($num >= 1 && $num <= 35) $redStats40[$num]++; }
                    foreach ($blues as $num) { if ($num >= 1 && $num <= 12) $blueStats40[$num]++; }
                }

                foreach ($redStats40 as $num => $count) { if ($count < 5) $killRedBalls[] = $num; }
                foreach ($blueStats40 as $num => $count) { if ($count < 5) $killBlueBalls[] = $num; }

                // ---- 核心二：近 5 期热号过滤 (出现次数 > 3) ----
                $history5 = DB::table('dlt_lotto_history')
                    ->select(['front1', 'front2', 'front3', 'front4', 'front5', 'back1', 'back2'])
                    ->orderBy('issue', 'desc')
                    ->limit(5)
                    ->get();

                $redStats5 = []; $blueStats5 = [];
                foreach ($history5 as $row) {
                    foreach (['front1', 'front2', 'front3', 'front4', 'front5'] as $field) {
                        $num = intval($row->$field);
                        if ($num > 0) { $redStats5[$num] = ($redStats5[$num] ?? 0) + 1; }
                    }
                    foreach (['back1', 'back2'] as $field) {
                        $num = intval($row->$field);
                        if ($num > 0) { $blueStats5[$num] = ($blueStats5[$num] ?? 0) + 1; }
                    }
                }

                foreach ($redStats5 as $num => $count) {
                    if ($count > 3 && !in_array($num, $killRedBalls)) { $killRedBalls[] = $num; }
                }
                foreach ($blueStats5 as $num => $count) {
                    if ($count > 3 && !in_array($num, $killBlueBalls)) { $killBlueBalls[] = $num; }
                }
            }

            // =================================================================
            // 3. 数据规整与安全写入
            // =================================================================
            if ($lotteryType) {
                sort($killRedBalls);
                sort($killBlueBalls);

                DB::table('lottery_kill_histories')->updateOrInsert(
                    [
                        'lottery_type' => $lotteryType,
                        'period'       => $lotterySetting->issue,
                    ],
                    [
                        'kill_red_balls'        => json_encode(array_values($killRedBalls)),
                        'kill_blue_balls'       => json_encode(array_values($killBlueBalls)),
                        'status'                => 0, 
                        'open_red_balls'        => null,
                        'open_blue_balls'       => null,
                        'wrong_kill_red_balls'  => null,
                        'wrong_kill_blue_balls' => null,
                        'created_at'            => now(),
                        'updated_at'            => now(),
                    ]
                );
            }
        });
    }
}