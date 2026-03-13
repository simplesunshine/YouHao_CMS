<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class SsqFushiController extends Controller
{

    /**
     * 双色球普通复式生成（红球+篮球）
     */
    public function normalFushi(Request $request)
    {
        $key = 'ssq_fushi_ip_'.$request->ip();

        if (Cache::has($key)) {
            return response()->json(['code'=>400,'msg'=>'操作太频繁']);
        }

        Cache::put($key,1,1); // 1秒限制


        $redCount = intval($request->input('red_count', 6));
        $blueCount = intval($request->input('blue_count', 1));

        if ($redCount < 6 || $redCount > 20) {
            return response()->json(['code' => 400, 'msg' => '红球数量不合法']);
        }

        if ($blueCount < 1 || $blueCount > 16) {
            return response()->json(['code' => 400, 'msg' => '篮球数量不合法']);
        }

        // 获取红球遗漏值和上期前区号码
        $row = DB::table('ssq_lotto_history')
            ->select('red_cold_json', 'front_numbers')
            ->orderByDesc('id')
            ->first();

        if (!$row) {
            return response()->json(['code' => 500, 'msg' => '历史数据不存在']);
        }

        $redOmit = json_decode($row->red_cold_json, true); // 1-33 遗漏值
        $lastFront = explode(',', $row->front_numbers);
        $lastFront = array_map('intval', $lastFront); // 转成整数数组

        $try = 0;

        while ($try < 100) {  // 尝试100次
            $try++;

            // 红球随机
            $reds = collect(range(1, 33))->shuffle()->take($redCount)->sort()->values()->toArray();

            // 获取遗漏值
            $redOmits = [];
            foreach ($reds as $n) {
                $redOmits[] = $redOmit[$n] ?? 0;
            }

            // 红球规则校验
            if (max($redOmits) < 6) continue;
            if (min($redOmits) > 1) continue;
            if (!$this->hasPrime($reds)) continue;
            if ((max($reds) - min($reds)) < 11) continue;

            // 如果遗漏值 >5 的号码 ≥4 个，重新生成
            if ( $redCount < 10 ) 
            {
                $highOmitCount = 0;
                foreach ($redOmits as $omit) {
                    if ($omit > 4) {
                        $highOmitCount++;
                    }
                }
                if ($highOmitCount >= 4) continue;
            }     

            // 篮球随机（不需要规则）
            $blues = collect(range(1, 16))->shuffle()->take($blueCount)->sort()->values()->toArray();

            DB::table('ssq_fushi_records')->insert([
                'issue' => $request->input('issue'), // 普通复式
                'mode' => 'normal', // 普通复式
                'red_dan' => '', // 没有胆码
                'kill_numbers' => '', // 没有杀号
                'red_count' => $redCount,
                'blue_count' => $blueCount,
                'red_numbers' => implode(',', $reds),
                'blue_numbers' => implode(',', $blues),
                'ip' => $request->ip(),
                'created_at' => now()
            ]);

            // 返回结果
            return response()->json([
                'code' => 200,
                'data' => [
                    'red' => $reds,
                    'red_omit' => $redOmits,
                    'blue' => $blues
                ]
            ]);
        }

        return response()->json(['code' => 500, 'msg' => '生成失败，请重试']);
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
     * 双色球胆拖复式生成
     */
    public function dantuoFushi(Request $request)
    {
        $key = 'ssq_fushi_ip_'.$request->ip();

        if (Cache::has($key)) {
            return response()->json(['code'=>400,'msg'=>'操作太频繁']);
        }

        Cache::put($key,1, now()->addSeconds(1)); // 1秒限制

        // 前端传入参数
        $tuoCount = intval($request->input('red_count', 6));
        $blueCount = intval($request->input('blue_count', 1));
        $danCount = intval($request->input('dan_count', 1)); // 胆码数量固定由前端传入

        // 参数合法性验证
        if ($danCount < 1 || $danCount > 5) {
            return response()->json(['code'=>400,'msg'=>'胆码数量不合法']);
        }
        if ($tuoCount < 6 || $tuoCount > 20) {
            return response()->json(['code'=>400,'msg'=>'拖码数量不合法']);
        }
        if ($blueCount < 1 || $blueCount > 16) {
            return response()->json(['code'=>400,'msg'=>'篮球数量不合法']);
        }

        // 获取遗漏值和上期号码
        $row = DB::table('ssq_lotto_history')
            ->select('red_cold_json','front_numbers')
            ->orderByDesc('id')
            ->first();

        if (!$row) {
            return response()->json(['code'=>500,'msg'=>'历史数据不存在']);
        }

        $redOmit = json_decode($row->red_cold_json,true);
        $lastFront = array_map('intval', explode(',', $row->front_numbers));

        $try = 0;

        while ($try < 100) {
            $try++;

            // 生成胆码
            $danNums = collect(range(1,33))->shuffle()->take($danCount)->toArray();
            sort($danNums);

            // 生成拖码（排除胆码）
            $tuoPool = array_diff(range(1,33), $danNums);
            $tuoNums = collect($tuoPool)->shuffle()->take($tuoCount)->toArray();
            sort($tuoNums);

            // 最终红球 = 胆码 + 拖码
            $reds = array_merge($danNums, $tuoNums);
            sort($reds);

            // 红球遗漏值
            $redOmits = [];
            foreach ($reds as $n) {
                $redOmits[] = $redOmit[$n] ?? 0;
            }

            // 红球规则校验
            if (max($redOmits) < 6) continue;
            if (min($redOmits) > 1) continue;
            if ((max($reds) - min($reds)) < 11) continue;

            if (count($reds) <= 10) {
                $highOmitCount = 0;
                foreach ($redOmits as $omit) {
                    if ($omit > 4) $highOmitCount++;
                }
                if ($highOmitCount >= 4) continue;
            }

            // 篮球随机
            $blues = collect(range(1,16))->shuffle()->take($blueCount)->sort()->values()->toArray();

            // 存库
            DB::table('ssq_fushi_records')->insert([
                'issue' => $request->input('issue'),
                'mode' => 'dan',
                'red_dan' => implode(',', $danNums),
                'kill_numbers' => '',
                'red_count' => $tuoCount,
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
                    'dan'=>$danNums,
                    'tuo'=>$tuoNums,
                    'blue'=>$blues
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