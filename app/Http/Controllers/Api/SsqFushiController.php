<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class SsqFushiController extends Controller
{
    /**
     * 内部限流逻辑：基于 User ID
     */
    private function checkRateLimit($user)
    {
        $cacheKey = 'ssq_fushi_limit_user_' . $user->id;

        if (Cache::has($cacheKey)) {
            return false;
        }

        // 设置 1 秒限流
        Cache::put($cacheKey, 1, 1);
        return true;
    }

    /**
     * 1. 双色球普通复式生成
     */
    public function normalFushi(Request $request)
    {
        $user = $request->user();
        if (!$this->checkRateLimit($user)) {
            return response()->json(['code' => 429, 'msg' => '操作太频繁，请稍后再试'], 429);
        }

        $redCount = intval($request->input('red_count', 6));
        $blueCount = intval($request->input('blue_count', 1));

        if ($redCount < 6 || $redCount > 20 || $blueCount < 1 || $blueCount > 16) {
            return response()->json(['code' => 400, 'msg' => '球数不合法']);
        }

        $row = DB::table('ssq_lotto_history')->select('red_ball_omission')->orderByDesc('id')->first();
        if (!$row) return response()->json(['code' => 500, 'msg' => '历史数据不存在']);
        $redOmit = json_decode($row->red_ball_omission, true);

        $try = 0;
        while ($try < 100) {
            $try++;
            $reds = collect(range(1, 33))->shuffle()->take($redCount)->sort()->values()->toArray();
            $redOmits = array_map(fn($n) => $redOmit[$n] ?? 0, $reds);

            if (max($redOmits) < 6 || min($redOmits) > 1 || !$this->hasPrime($reds) || (max($reds) - min($reds)) < 11) continue;

            $blues = collect(range(1, 16))->shuffle()->take($blueCount)->sort()->values()->toArray();

            // 存入统一记录表 - 修复字段名为 lottery_type
            DB::table('user_lotto_records')->insert([
                'user_id'      => $user->id,
                'lottery_type' => 'ssq', // <--- 修复点
                'is_fushi'     => 1,
                'issue'        => $request->input('issue'),
                'mode'         => 'normal_fushi',
                'red_dan'      => '',
                'kill_numbers' => '',
                'red_numbers'  => implode(',', $reds),
                'blue_numbers' => implode(',', $blues),
                'ip'           => $request->ip(),
                'created_at'   => now(),
                'updated_at'   => now(), // 建议加上
            ]);

            return response()->json([
                'code' => 200,
                'data' => ['red' => $reds, 'red_omit' => $redOmits, 'blue' => $blues]
            ]);
        }

        return response()->json(['code' => 500, 'msg' => '生成失败，请重试']);
    }

    /**
     * 2. 双色球胆拖复式生成
     */
    public function dantuoFushi(Request $request)
    {
        $user = $request->user();
        if (!$this->checkRateLimit($user)) {
            return response()->json(['code' => 429, 'msg' => '操作太频繁'], 429);
        }

        $tuoCount = intval($request->input('red_count', 6));
        $blueCount = intval($request->input('blue_count', 1));
        $danCount = intval($request->input('dan_count', 1));
        
        // 新增：获取前端传来的推荐胆码组合
        $preferDanStr = $request->input('prefer_dan', '');
        $preferDans = !empty($preferDanStr) ? explode(',', $preferDanStr) : [];

        if ($danCount < 1 || $danCount > 5 || $tuoCount < 6 || $blueCount < 1) {
            return response()->json(['code' => 400, 'msg' => '参数不合法']);
        }

        $row = DB::table('ssq_lotto_history')->select('red_ball_omission')->orderByDesc('id')->first();
        $redOmit = json_decode($row->red_ball_omission, true);

        $try = 0;
        while ($try < 100) {
            $try++;
            
            // --- 修改逻辑：胆码生成 ---
            if (!empty($preferDans)) {
                // 如果有推荐号，使用推荐号作为胆码。如果推荐号数量不足 danCount，随机补齐。
                $baseDans = array_map('intval', $preferDans);
                if (count($baseDans) < $danCount) {
                    $extraPool = array_diff(range(1, 33), $baseDans);
                    $extraDans = collect($extraPool)->shuffle()->take($danCount - count($baseDans))->toArray();
                    $danNums = array_merge($baseDans, $extraDans);
                } else {
                    // 如果推荐号多于设定的danCount，则截取
                    $danNums = array_slice($baseDans, 0, $danCount);
                }
            } else {
                // 原有随机生成逻辑
                $danNums = collect(range(1, 33))->shuffle()->take($danCount)->toArray();
            }
            sort($danNums);
            // ------------------------

            $tuoPool = array_diff(range(1, 33), $danNums);
            $tuoNums = collect($tuoPool)->shuffle()->take($tuoCount)->sort()->values()->toArray();

            $reds = array_merge($danNums, $tuoNums);
            sort($reds);
            $redOmits = array_map(fn($n) => $redOmit[$n] ?? 0, $reds);

            // 保持原有过滤条件：最大遗漏 < 6 或 最小遗漏 > 1 或 跨度 < 11 则重试
            if (max($redOmits) < 6 || min($redOmits) > 1 || (max($reds) - min($reds)) < 11) continue;

            $blues = collect(range(1, 16))->shuffle()->take($blueCount)->sort()->values()->toArray();

            DB::table('user_lotto_records')->insert([
                'user_id'      => $user->id,
                'lottery_type' => 'ssq',
                'is_fushi'     => 1,
                'issue'        => $request->input('issue'),
                'mode'         => 'dantuo_fushi',
                'red_dan'      => implode(',', $danNums),
                'kill_numbers' => '',
                'red_numbers'  => implode(',', $tuoNums),
                'blue_numbers' => implode(',', $blues),
                'ip'           => $request->ip(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            return response()->json([
                'code' => 200,
                'data' => [
                    'dan' => $danNums, 
                    'tuo' => $tuoNums, 
                    'blue' => $blues
                ]
            ]);
        }

        return response()->json(['code' => 500, 'msg' => '生成失败，请重试']);
    }

    /**
     * 3. 固定杀号复式
     */
    public function fixedKillFushi(Request $request)
    {
        $user = $request->user();
        if (!$this->checkRateLimit($user)) {
            return response()->json(['code' => 429, 'msg' => '操作太频繁'], 429);
        }

        $redCount = intval($request->input('red_count', 6));
        $blueCount = intval($request->input('blue_count', 1));
        $killNumbers = $request->input('kill_numbers', '');

        $row = DB::table('ssq_lotto_history')->select('red_ball_omission')->orderByDesc('id')->first();
        if (!$row) return response()->json(['code' => 500, 'msg' => '数据不存在']);
        
        $redOmit = json_decode($row->red_ball_omission, true);
        $killArr = array_map('intval', array_filter(explode(',', $killNumbers)));

        $try = 0;
        while ($try < 100) {
            $try++;
            $pool = array_diff(range(1, 33), $killArr);
            $tuoNums = collect($pool)->shuffle()->take($redCount)->sort()->values()->toArray();
            $redOmits = array_map(fn($n) => $redOmit[$n] ?? 0, $tuoNums);

            if (max($redOmits) < 6 || min($redOmits) > 1) continue;

            $blues = collect(range(1, 16))->shuffle()->take($blueCount)->sort()->values()->toArray();

            DB::table('user_lotto_records')->insert([
                'user_id'      => $user->id,
                'lottery_type' => 'ssq', // <--- 修复点
                'is_fushi'     => 1,
                'issue'        => $request->input('issue'),
                'mode'         => 'guding_kill_fushi',
                'red_dan'      => '',
                'kill_numbers' => implode(',', $killArr),
                'red_numbers'  => implode(',', $tuoNums),
                'blue_numbers' => implode(',', $blues),
                'ip'           => $request->ip(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            return response()->json(['code' => 200, 'data' => ['red' => $tuoNums, 'blue' => $blues]]);
        }

        return response()->json(['code' => 500, 'msg' => '生成失败']);
    }

    /**
     * 4. 用户自定义杀号复式
     */
    public function userKillFushi(Request $request)
    {
        $user = $request->user();
        if (!$this->checkRateLimit($user)) {
            return response()->json(['code' => 429, 'msg' => '操作太频繁'], 429);
        }

        $redCount = intval($request->input('red_count', 6));
        $blueCount = intval($request->input('blue_count', 1));
        $killNumbers = $request->input('kill_numbers', '');

        $row = DB::table('ssq_lotto_history')->select('red_ball_omission')->orderByDesc('id')->first();
        $redOmit = json_decode($row->red_ball_omission, true);
        $killArr = array_map('intval', array_filter(explode(',', $killNumbers)));

        $try = 0;
        while ($try < 100) {
            $try++;
            $pool = array_diff(range(1, 33), $killArr);
            $tuoNums = collect($pool)->shuffle()->take($redCount)->sort()->values()->toArray();
            $redOmits = array_map(fn($n) => $redOmit[$n] ?? 0, $tuoNums);

            if (max($redOmits) < 6 || min($redOmits) > 1) continue;

            $blues = collect(range(1, 16))->shuffle()->take($blueCount)->sort()->values()->toArray();

            DB::table('user_lotto_records')->insert([
                'user_id'      => $user->id,
                'lottery_type' => 'ssq', // <--- 修复点
                'is_fushi'     => 1,
                'issue'        => $request->input('issue'),
                'mode'         => 'diy_kill_fushi',
                'red_dan'      => '',
                'kill_numbers' => implode(',', $killArr),
                'red_numbers'  => implode(',', $tuoNums),
                'blue_numbers' => implode(',', $blues),
                'ip'           => $request->ip(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            return response()->json(['code' => 200, 'data' => ['tuo' => $tuoNums, 'blue' => $blues]]);
        }

        return response()->json(['code' => 500, 'msg' => '生成失败']);
    }

    private function hasPrime($nums)
    {
        $primes = [2, 3, 5, 7, 11, 13, 17, 19, 23, 29, 31];
        foreach ($nums as $n) {
            if (in_array($n, $primes)) return true;
        }
        return false;
    }
}