<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\LottoDltRecommendation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Services\DltLotteryFeatureService;

class DltController extends Controller
{
    /**
     * 内部限流逻辑：基于 User ID (1秒1次)
     */
    private function checkRateLimit($user)
    {
        $cacheKey = 'dlt_pick_limit_user_' . $user->id;

        if (Cache::has($cacheKey)) {
            return false;
        }

        Cache::put($cacheKey, 1, 1);
        return true;
    }

    /**
     * 大乐透机选接口 (集成自动保存与期号修复)
     */
    public function pick(Request $request)
    {
        $user = $request->user();
        $ip = $request->ip();

        // 1. 频率限制
        if (!$this->checkRateLimit($user)) {
            return response()->json([
                'success' => false,
                'message' => '操作太频繁，请稍后再试'
            ], 429);
        }

        // 2. 总次数限制 (基于统一表查询已抽取数量)
        $count = DB::table('user_lotto_records')
            ->where('user_id', $user->id)
            ->where('lottery_type', 'dlt')
            ->count();
            
        $maxPerUser = 500;
        $remaining = $maxPerUser - $count;

        if ($remaining <= 0) {
            return response()->json([
                'success' => false,
                'message' => '今日随机次数已用完',
                'remain' => 0
            ]);
        }

        $take  = min(5, $remaining);
        $type  = $request->input('type', 'normal');
        $prefs = $request->input('prefs', []);

        $service = new DltLotteryFeatureService();
        $results = collect();
        $query = LottoDltRecommendation::whereNull('ip');

        // 3. 玩法逻辑分支
        switch ($type) {
            case 'normal':
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            case 'first_advantage':
                $topAdv = Cache::remember('dlt_first_adv_top5', 60, function () {
                    $issues = DB::table('dlt_lotto_history')->orderByDesc('id')->limit(80)->pluck('front1');
                    $map = [];
                    foreach ($issues as $num) { $map[$num] = ($map[$num] ?? 0) + 1; }
                    arsort($map);
                    return array_slice($map, 0, 5, true);
                });
                $results = $query->whereIn('front_1', array_keys($topAdv))->inRandomOrder()->take($take)->get();
                break;

            case 'connect':
                $consecutive = (int)($prefs['serial'] ?? 0);
                if ($consecutive <= 0) return response()->json(['success' => false, 'message' => '请选择连号个数'], 400);
                $results = $query->where('consecutive_count', $consecutive)->inRandomOrder()->take($take)->get();
                break;

            case 'dan_only':
                $frontDan = (array)($prefs['front_dan'] ?? []);
                if (empty($frontDan) || count($frontDan) > 4) return response()->json(['success' => false, 'message' => '前区胆码数量 1-4 个'], 400);
                foreach ($frontDan as $num) {
                    $query->where(function ($q) use ($num) {
                        $q->orWhere('front_1', $num)->orWhere('front_2', $num)->orWhere('front_3', $num)->orWhere('front_4', $num)->orWhere('front_5', $num);
                    });
                }
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            case 'history_sum':
                $excludeCount = (int)$request->input('exclude', 0);
                $excludeSums = $excludeCount > 0 ? DB::table('dlt_lotto_history')->orderByDesc('issue')->limit($excludeCount)->pluck('front_sum')->toArray() : [];
                if (!empty($excludeSums)) $query->whereNotIn('front_sum', $excludeSums);
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            case 'odd_even':
                if (empty($prefs['odd_even'])) return response()->json(['success' => false, 'message' => '请选择奇偶比'], 400);
                [$odd, $even] = explode(':', $prefs['odd_even']);
                $results = $query->where('odd_count', (int)$odd)->where('even_count', (int)$even)->inRandomOrder()->take($take)->get();
                break;

            case 'first_last':
                if (!empty($prefs['first'])) $query->where('front_1', $prefs['first']);
                if (!empty($prefs['last'])) $query->where('front_5', $prefs['last']);
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            default:
                $results = $query->inRandomOrder()->take($take)->get();
        }

        if ($results->isEmpty()) {
            return response()->json(['success' => false, 'message' => '没有符合条件的号码']);
        }

        // 4. 构建返回数据
        $randomData = $results->map(fn($row) => $service->buildRow($row));

        // 5. ⭐ 自动保存到永久记录表 (包含期号修复逻辑)
        $records = [];
        foreach ($results as $row) {
            $issue = $row->issue;
            // 修复期号：如果小于 7 位（如 26001），则补齐为 2026001
            if (strlen($issue) < 7) {
                $issue = '20' . $issue;
            }

            $records[] = [
                'user_id'       => $user->id,
                'lottery_type'  => 'dlt',
                'is_fushi'      => 0, // 机选默认为单式
                'issue'         => $issue,
                'mode'          => $type,
                'red_numbers'   => $row->front_numbers, // 前区
                'blue_numbers'  => $row->back_numbers,  // 后区
                'red_dan'       => '',
                'kill_numbers'  => '',
                'is_win'        => 0,
                'ip'            => $ip,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }
        DB::table('user_lotto_records')->insert($records);

        // 标记推荐池记录
        LottoDltRecommendation::whereIn('id', $results->pluck('id'))->update([
            'ip'      => $ip,
            'user_id' => $user->id,
            'mode'    => $type
        ]);

        return response()->json([
            'success' => true,
            'data'    => $randomData,
            'remain'  => $remaining - $results->count(),
        ]);
    }

    /**
     * 大乐透号码分布分析
     */
    public function numberDistribution(Request $request)
    {
        $periods = min(max((int)$request->input('periods', 50), 1), 3000);

        $history = DB::table('dlt_lotto_history')->orderByDesc('issue')->limit($periods)
            ->get(['front1','front2','front3','front4','front5','back1','back2']);

        if ($history->isEmpty()) {
            return response()->json(['code' => 200, 'data' => ['front' => [[],[],[],[],[]], 'back'  => [[],[]]]]);
        }

        $front = array_fill(0, 5, []);
        $back  = array_fill(0, 2, []);

        foreach ($history as $row) {
            for ($i = 1; $i <= 5; $i++) {
                $num = (int) $row->{'front'.$i};
                $front[$i-1][$num] = ($front[$i-1][$num] ?? 0) + 1;
            }
            for ($i = 1; $i <= 2; $i++) {
                $num = (int) $row->{'back'.$i};
                $back[$i-1][$num] = ($back[$i-1][$num] ?? 0) + 1;
            }
        }

        $format = function ($arr) {
            $out = [];
            foreach ($arr as $num => $count) { $out[] = ['number' => $num, 'count'  => $count]; }
            return $out;
        };

        return response()->json([
            'code' => 200,
            'data' => [
                'front' => array_map($format, $front),
                'back'  => array_map($format, $back),
            ]
        ]);
    }

    /**
     * 获取上期开奖 (带期号修复)
     */
    public function lastIssue(Request $request)
    {
        $last = DB::table('dlt_lotto_history')->orderByDesc('id')->first();
        if (!$last) return response()->json(['success' => false, 'message' => '暂无历史开奖数据']);

        $issue = $last->issue;
        if (strlen($issue) < 7) {
            $issue = '20' . $issue;
        }

        $redCold = json_decode($last->red_ball_omission, true) ?? [];
        $maxCold = $redCold ? max($redCold) : 0;
        $coldNumbers = [];
        foreach ($redCold as $num => $val) {
            if ($val === $maxCold && $val > 0) $coldNumbers[] = (int)$num;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'issue'         => $issue,
                'front_numbers' => $last->front_numbers,
                'back_numbers'  => $last->back_numbers,
                'features' => [
                    'cold_numbers'   => $coldNumbers,
                    'continue_count' => $last->continue_count ?? 0
                ]
            ]
        ]);
    }

    /**
     * 后区组合统计
     */
    public function backComboStats(Request $request)
    {
        $periods = min(max((int)$request->input('periods', 660), 1), 3000);
        $history = DB::table('dlt_lotto_history')->orderByDesc('issue')->limit($periods)->get(['back1','back2']);

        $stats = [];
        foreach ($history as $row) {
            $a = min($row->back1, $row->back2);
            $b = max($row->back1, $row->back2);
            $key = sprintf('%02d-%02d', $a, $b);
            if (!isset($stats[$key])) $stats[$key] = ['combo' => $key, 'n1' => $a, 'n2' => $b, 'count' => 0];
            $stats[$key]['count']++;
        }
        usort($stats, fn($a, $b) => $b['count'] <=> $a['count']);

        return response()->json(['code' => 200, 'data' => array_values($stats)]);
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