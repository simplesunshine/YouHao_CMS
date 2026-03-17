<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class DltFushiController extends Controller
{

    /**
     * 普通复式生成（红球+篮球）
     */
    public function normalFushi(Request $request)
    {
        $key = 'dlt_fushi_ip_'.$request->ip();

        if (Cache::has($key)) {
            return response()->json(['code'=>400,'msg'=>'操作太频繁']);
        }

        Cache::put($key,1,1);

        $redCount = intval($request->input('red_count', 5));
        $blueCount = intval($request->input('blue_count', 2));

        if ($redCount < 5 || $redCount > 15) {
            return response()->json(['code'=>400,'msg'=>'前区数量不合法']);
        }

        if ($blueCount < 2 || $blueCount > 12) {
            return response()->json(['code'=>400,'msg'=>'后区数量不合法']);
        }

        // 大乐透历史
        $row = DB::table('dlt_lotto_history')
            ->select('red_cold_json','front_numbers')
            ->orderByDesc('id')
            ->first();

        if (!$row) {
            return response()->json(['code'=>500,'msg'=>'历史数据不存在']);
        }

        $redOmit = json_decode($row->red_cold_json,true);

        $try = 0;

        while ($try < 100) {

            $try++;

            // 前区随机 1-35
            $reds = collect(range(1,35))
                ->shuffle()
                ->take($redCount)
                ->sort()
                ->values()
                ->toArray();

            // 获取遗漏值
            $redOmits = [];

            foreach ($reds as $n) {
                $redOmits[] = $redOmit[$n] ?? 0;
            }

            /* 规则1：如果遗漏值全部 ≤2 重新生成 */
            if (max($redOmits) <= 2) continue;

            /* 规则2：如果遗漏值全部 >2 重新生成 */
            if (min($redOmits) > 2) continue;

            /* 规则3：跨度小于12重新生成 */
            if ((max($reds) - min($reds)) < 12) continue;

            /* 规则4：没有质数重新生成 */
            if (!$this->hasPrime($reds)) continue;

            // 后区 1-12
            $blues = collect(range(1,12))
                ->shuffle()
                ->take($blueCount)
                ->sort()
                ->values()
                ->toArray();

            DB::table('dlt_fushi_records')->insert([
                'issue' => $request->input('issue'),
                'mode' => 'normal',
                'red_dan' => '',
                'kill_numbers' => '',
                'red_count' => $redCount,
                'blue_count' => $blueCount,
                'red_numbers' => implode(',', $reds),
                'blue_numbers' => implode(',', $blues),
                'ip' => $request->ip(),
                'created_at' => now()
            ]);

            return response()->json([
                'code'=>200,
                'data'=>[
                    'red'=>$reds,
                    'red_omit'=>$redOmits,
                    'blue'=>$blues
                ]
            ]);
        }

        return response()->json(['code'=>500,'msg'=>'生成失败，请重试']);
    }

    /**
     * 判断是否包含质数
     */
    private function hasPrime($nums)
    {
        $primes = [2,3,5,7,11,13,17,19,23,29,31];
        foreach ($nums as $n) {
            if (in_array($n, $primes)) return true;
        }
        return false;
    }

    /**
     * 大乐透胆拖复式生成（前区胆拖 + 后区普通）
     */
    public function dantuoFushi(Request $request)
    {
        $key = 'dlt_fushi_ip_'.$request->ip();

        if (Cache::has($key)) {
            return response()->json(['code'=>400,'msg'=>'操作太频繁']);
        }

        Cache::put($key,1,1);

        // 参数
        $danCount = intval($request->input('red_dan_count', 1));
        $tuoCount = intval($request->input('red_tuo_count', 4));
        $blueCount = intval($request->input('blue_count', 2));

        // 校验
        if ($danCount < 1 || $danCount > 4) {
            return response()->json(['code'=>400,'msg'=>'胆码数量不合法']);
        }

        if ($tuoCount < 2 || $tuoCount > 15) {
            return response()->json(['code'=>400,'msg'=>'拖码数量不合法']);
        }

        if (($danCount + $tuoCount) < 5) {
            return response()->json(['code'=>400,'msg'=>'前区总数必须≥5']);
        }

        if ($blueCount < 2 || $blueCount > 12) {
            return response()->json(['code'=>400,'msg'=>'后区数量不合法']);
        }

        // 获取大乐透历史
        $row = DB::table('dlt_lotto_history')
            ->select('red_cold_json','front_numbers')
            ->orderByDesc('id')
            ->first();

        if (!$row) {
            return response()->json(['code'=>500,'msg'=>'历史数据不存在']);
        }

        $redOmit = json_decode($row->red_cold_json,true);

        $try = 0;

        while ($try < 100) {

            $try++;

            // ===== 生成胆码 =====
            $danNums = collect(range(1,35))
                ->shuffle()
                ->take($danCount)
                ->toArray();

            // ===== 生成拖码（排除胆码）=====
            $tuoPool = array_diff(range(1,35), $danNums);

            $tuoNums = collect($tuoPool)
                ->shuffle()
                ->take($tuoCount)
                ->toArray();

            // 合并前区
            $reds = array_merge($danNums, $tuoNums);
            sort($reds);

            // ===== 获取遗漏值 =====
            $redOmits = [];

            foreach ($reds as $n) {
                $redOmits[] = $redOmit[$n] ?? 0;
            }

            /* ===== 复用 normalFushi 规则 ===== */

            // 规则1：不能全小遗漏
            if (max($redOmits) <= 2) continue;

            // 规则2：不能全大遗漏
            if (min($redOmits) > 2) continue;

            // 规则3：跨度
            if ((max($reds) - min($reds)) < 12) continue;

            // 规则4：必须有质数
            if (!$this->hasPrime($reds)) continue;

            // ===== 后区 =====
            $blues = collect(range(1,12))
                ->shuffle()
                ->take($blueCount)
                ->sort()
                ->values()
                ->toArray();

            sort($danNums);
            sort($tuoNums);
            // ===== 存库 =====
            DB::table('dlt_fushi_records')->insert([
                'issue' => $request->input('issue'),
                'mode' => 'dantuo',
                'red_dan' => implode(',', $danNums),
                'kill_numbers' => '',
                'red_count' => $tuoCount,
                'blue_count' => $blueCount,
                'red_numbers' => implode(',', $tuoNums),
                'blue_numbers' => implode(',', $blues),
                'ip' => $request->ip(),
                'created_at' => now()
            ]);

            // ===== 返回 =====
            return response()->json([
                'code'=>200,
                'data'=>[
                    'dan'  => array_values($danNums),
                    'tuo'  => array_values($tuoNums),
                    'blue' => $blues
                ]
            ]);
        }

        return response()->json(['code'=>500,'msg'=>'生成失败，请重试']);
    }


    /**
     * 固定杀号复式
     */
    public function fixedKillFushi(Request $request)
    {
        $key = 'ssq_fushi_ip_'.$request->ip();
        if (Cache::has($key)) {
            return response()->json(['code'=>400,'msg'=>'操作太频繁']);
        }
        Cache::put($key, 1, now()->addSeconds(1));

        // 前端传入
        $redCount = intval($request->input('red_count', 6)); // 红球总数
        $blueCount = intval($request->input('blue_count', 1)); // 蓝球数量
        $killNumbers = $request->input('kill_numbers', ''); // 固定杀号数组，例如 [4,9,14,19,24,29]
        $issue = $request->input('issue','');

        if ($redCount < 6 || $redCount > 20) return response()->json(['code'=>400,'msg'=>'红球数量不合法']);
        if ($blueCount < 1 || $blueCount > 16) return response()->json(['code'=>400,'msg'=>'篮球数量不合法']);

        // 获取遗漏值和上期号码
        $row = DB::table('ssq_lotto_history')
            ->select('red_cold_json','front_numbers')
            ->orderByDesc('id')
            ->first();

        if (!$row) return response()->json(['code'=>500,'msg'=>'历史数据不存在']);

        $redOmit = json_decode($row->red_cold_json,true);
        $lastFront = array_map('intval', explode(',', $row->front_numbers));

        $killNumbers = explode(',', $killNumbers);      // 拆成数组
        $killNumbers = array_map('intval', $killNumbers); // 转成整数

        $try = 0;
        while ($try < 100) {
            $try++;

            // 红球池排除杀号
            $pool = array_diff(range(1, 33), $killNumbers);  // 排除杀号
            $tuoNums = collect($pool)->shuffle()->take($redCount)->sort()->values()->toArray();

            // 红球遗漏值
            $redOmits = [];
            foreach ($tuoNums as $n) $redOmits[] = $redOmit[$n] ?? 0;

            // 红球规则校验
            if (max($redOmits) < 6) continue;
            if (min($redOmits) > 1) continue;
            if ((max($tuoNums)-min($tuoNums)) < 11) continue;
            if ($redCount <= 10) {
                $highOmitCount = 0;
                foreach ($redOmits as $omit) if ($omit > 4) $highOmitCount++;
                if ($highOmitCount >= 4) continue;
            }

            // 篮球随机
            $blues = collect(range(1,16))->shuffle()->take($blueCount)->sort()->values()->toArray();

            // 存库
            DB::table('ssq_fushi_records')->insert([
                'issue' => $issue,
                'mode' => 'kill', // 固定杀号模式
                'red_dan' => '', // 没有胆码
                'kill_numbers' => implode(',', $killNumbers),
                'red_count' => $redCount,
                'blue_count' => $blueCount,
                'red_numbers' => implode(',', $tuoNums),
                'blue_numbers' => implode(',', $blues),
                'ip' => $request->ip(),
                'created_at' => now()
            ]);

            // 返回结果
            return response()->json([
                'code'=>200,
                'data'=>[
                    'red' => $tuoNums,
                    'blue' => $blues
                ]
            ]);
        }

        return response()->json(['code'=>500,'msg'=>'生成失败，请重试']);
    }

    /**
     * 用户自定义杀号复式
     */
    public function userKillFushi(Request $request)
    {
        $key = 'ssq_fushi_ip_'.$request->ip();
        if (Cache::has($key)) {
            return response()->json(['code'=>400,'msg'=>'操作太频繁']);
        }
        Cache::put($key, 1, now()->addSeconds(1));

        // 前端传入
        $redCount = intval($request->input('red_count', 6)); // 红球总数
        $blueCount = intval($request->input('blue_count', 1)); // 蓝球数量
        $killNumbers = $request->input('kill_numbers', '');   // 用户选择的杀号，例如 "4,9,14"
        $issue = $request->input('issue','');

        if ($redCount < 6 || $redCount > 20) return response()->json(['code'=>400,'msg'=>'红球数量不合法']);
        if ($blueCount < 1 || $blueCount > 16) return response()->json(['code'=>400,'msg'=>'篮球数量不合法']);

        // 获取遗漏值和上期号码
        $row = DB::table('ssq_lotto_history')
            ->select('red_cold_json','front_numbers')
            ->orderByDesc('id')
            ->first();

        if (!$row) return response()->json(['code'=>500,'msg'=>'历史数据不存在']);

        $redOmit = json_decode($row->red_cold_json,true);
        $lastFront = array_map('intval', explode(',', $row->front_numbers));

        // 转数组
        $killNumbers = explode(',', $killNumbers);
        $killNumbers = array_map('intval', $killNumbers);

        $try = 0;
        while ($try < 100) {
            $try++;

            // 红球池排除用户杀号
            $pool = array_diff(range(1,33), $killNumbers);
            $tuoNums = collect($pool)->shuffle()->take($redCount)->sort()->values()->toArray();

            // 红球遗漏值
            $redOmits = [];
            foreach ($tuoNums as $n) $redOmits[] = $redOmit[$n] ?? 0;

            // 红球规则校验
            if (max($redOmits) < 6) continue;
            if (min($redOmits) > 1) continue;
            if ((max($tuoNums)-min($tuoNums)) < 11) continue;
            if ($redCount <= 10) {
                $highOmitCount = 0;
                foreach ($redOmits as $omit) if ($omit > 4) $highOmitCount++;
                if ($highOmitCount >= 4) continue;
            }

            // 篮球随机
            $blues = collect(range(1,16))->shuffle()->take($blueCount)->sort()->values()->toArray();

            // 存库
            DB::table('ssq_fushi_records')->insert([
                'issue' => $issue,
                'mode' => 'kill_user', // 用户自定义杀号模式
                'red_dan' => '',
                'kill_numbers' => implode(',', $killNumbers),
                'red_count' => $redCount,
                'blue_count' => $blueCount,
                'red_numbers' => implode(',', $tuoNums),
                'blue_numbers' => implode(',', $blues),
                'ip' => $request->ip(),
                'created_at' => now()
            ]);

            // 返回前端
            return response()->json([
                'code'=>200,
                'data'=>[
                    'tuo' => $tuoNums,
                    'blue' => $blues,
                    'kill' => $killNumbers
                ]
            ]);
        }

        return response()->json(['code'=>500,'msg'=>'生成失败，请重试']);
    }
}