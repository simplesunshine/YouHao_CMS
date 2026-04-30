<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class DltFushiController extends Controller
{
    /**
     * 内部限流逻辑：基于 User ID (1秒1次)
     */
    private function checkRateLimit($user)
    {
        $cacheKey = 'dlt_fushi_limit_user_' . $user->id;
        if (Cache::has($cacheKey)) {
            return false;
        }
        Cache::put($cacheKey, 1, 1);
        return true;
    }

    /**
     * 1. 普通复式生成
     */
    public function normalFushi(Request $request)
    {
        $user = $request->user();
        if (!$this->checkRateLimit($user)) {
            return response()->json(['code' => 429, 'msg' => '操作太频繁'], 429);
        }

        $redCount = intval($request->input('red_count', 5));
        $blueCount = intval($request->input('blue_count', 2));

        if ($redCount < 5 || $redCount > 15 || $blueCount < 2 || $blueCount > 12) {
            return response()->json(['code' => 400, 'msg' => '球数不合法']);
        }

        $row = DB::table('dlt_lotto_history')->select('red_ball_omission')->orderByDesc('id')->first();
        if (!$row) return response()->json(['code' => 500, 'msg' => '历史数据不存在']);
        $redOmit = json_decode($row->red_ball_omission, true);

        $try = 0;
        while ($try < 100) {
            $try++;
            $reds = collect(range(1, 35))->shuffle()->take($redCount)->sort()->values()->toArray();
            $redOmits = array_map(fn($n) => $redOmit[$n] ?? 0, $reds);

            // 规则校验
            if (max($redOmits) <= 2 || min($redOmits) > 2) continue;
            if ((max($reds) - min($reds)) < 12) continue;
            if (!$this->hasPrime($reds)) continue;

            $blues = collect(range(1, 12))->shuffle()->take($blueCount)->sort()->values()->toArray();

            // 处理期号并存库
            $this->saveRecord($user, $request, 'normal_fushi', '', '', $reds, $blues);

            return response()->json([
                'code' => 200,
                'data' => ['red' => $reds, 'red_omit' => $redOmits, 'blue' => $blues]
            ]);
        }
        return response()->json(['code' => 500, 'msg' => '生成失败']);
    }

    /**
     * 2. 胆拖复式生成
     */
    public function dantuoFushi(Request $request)
    {
        $user = $request->user();
        if (!$this->checkRateLimit($user)) {
            return response()->json(['code' => 429, 'msg' => '操作太频繁'], 429);
        }

        $danCount = intval($request->input('red_dan_count', 1));
        $tuoCount = intval($request->input('red_tuo_count', 4));
        $blueCount = intval($request->input('blue_count', 2));

        if ($danCount < 1 || $danCount > 4 || $tuoCount < 2 || ($danCount + $tuoCount) < 5) {
            return response()->json(['code' => 400, 'msg' => '前区参数不合法']);
        }

        $row = DB::table('dlt_lotto_history')->select('red_ball_omission')->orderByDesc('id')->first();
        $redOmit = json_decode($row->red_ball_omission, true);

        $try = 0;
        while ($try < 100) {
            $try++;
            $danNums = collect(range(1, 35))->shuffle()->take($danCount)->sort()->values()->toArray();
            $tuoNums = collect(array_diff(range(1, 35), $danNums))->shuffle()->take($tuoCount)->sort()->values()->toArray();

            $reds = array_merge($danNums, $tuoNums);
            sort($reds);
            $redOmits = array_map(fn($n) => $redOmit[$n] ?? 0, $reds);

            if (max($redOmits) <= 2 || min($redOmits) > 2 || (max($reds) - min($reds)) < 12 || !$this->hasPrime($reds)) continue;

            $blues = collect(range(1, 12))->shuffle()->take($blueCount)->sort()->values()->toArray();

            $this->saveRecord($user, $request, 'dantuo_fushi', implode(',', $danNums), '', $tuoNums, $blues);

            return response()->json([
                'code' => 200,
                'data' => ['dan' => $danNums, 'tuo' => $tuoNums, 'blue' => $blues]
            ]);
        }
        return response()->json(['code' => 500, 'msg' => '生成失败']);
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

        $redCount = intval($request->input('red_count', 5));
        $blueCount = intval($request->input('blue_count', 2));
        $killNumbers = array_map('intval', array_filter(explode(',', $request->input('kill_numbers', ''))));

        $row = DB::table('dlt_lotto_history')->select('red_ball_omission')->orderByDesc('id')->first();
        $redOmit = json_decode($row->red_ball_omission, true);

        $try = 0;
        while ($try < 100) {
            $try++;
            $pool = array_diff(range(1, 35), $killNumbers);
            $reds = collect($pool)->shuffle()->take($redCount)->sort()->values()->toArray();
            $redOmits = array_map(fn($n) => $redOmit[$n] ?? 0, $reds);

            if (max($redOmits) <= 2 || min($redOmits) > 2 || !$this->hasPrime($reds)) continue;

            $blues = collect(range(1, 12))->shuffle()->take($blueCount)->sort()->values()->toArray();

            $this->saveRecord($user, $request, 'kill', '', implode(',', $killNumbers), $reds, $blues);

            return response()->json(['code' => 200, 'data' => ['red' => $reds, 'blue' => $blues]]);
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

        $redCount = intval($request->input('red_count', 5));
        $blueCount = intval($request->input('blue_count', 2));
        $killNumbers = array_map('intval', array_filter(explode(',', $request->input('kill_numbers', ''))));

        $row = DB::table('dlt_lotto_history')->select('red_ball_omission')->orderByDesc('id')->first();
        $redOmit = json_decode($row->red_ball_omission, true);

        $try = 0;
        while ($try < 100) {
            $try++;
            $pool = array_diff(range(1, 35), $killNumbers);
            $reds = collect($pool)->shuffle()->take($redCount)->sort()->values()->toArray();
            $redOmits = array_map(fn($n) => $redOmit[$n] ?? 0, $reds);

            if (max($redOmits) <= 2 || min($redOmits) > 2) continue;

            $blues = collect(range(1, 12))->shuffle()->take($blueCount)->sort()->values()->toArray();

            $this->saveRecord($user, $request, 'kill_user', '', implode(',', $killNumbers), $reds, $blues);

            return response()->json(['code' => 200, 'data' => ['tuo' => $reds, 'blue' => $blues]]);
        }
        return response()->json(['code' => 500, 'msg' => '生成失败']);
    }

    /**
     * 统一存储逻辑
     */
    private function saveRecord($user, $request, $mode, $dan, $kill, $reds, $blues)
    {
        $issue = $request->input('issue');
        if (strlen($issue) < 7) {
            $issue = '20' . $issue;
        }

        DB::table('user_lotto_records')->insert([
            'user_id'      => $user->id,
            'lottery_type' => 'dlt',
            'is_fushi'     => 1,
            'issue'        => $issue,
            'mode'         => $mode,
            'red_dan'      => $dan,
            'kill_numbers' => $kill,
            'red_numbers'  => implode(',', $reds),
            'blue_numbers' => implode(',', $blues),
            'ip'           => $request->ip(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
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