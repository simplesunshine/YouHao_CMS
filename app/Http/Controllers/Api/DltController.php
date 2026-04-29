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
     * 大乐透机选接口 (集成自动保存)
     */
    public function pick(Request $request)
    {
        $user = $request->user(); // 获取登录用户 (需配合路由中间件 auth:api)
        $ip = $request->ip();

        // 1. 次数限制 (基于用户ID)
        $count = LottoDltRecommendation::where('user_id', $user->id)->count();
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

        // 2. 玩法逻辑分支
        switch ($type) {
            case 'normal':
                $results = LottoDltRecommendation::whereNull('ip')
                    ->inRandomOrder()->take($take)->get();
                break;

            case 'first_advantage':
                $topAdv = Cache::remember('dlt_first_adv_top5', 60, function () {
                    $issues = DB::table('dlt_lotto_history')->orderByDesc('id')->limit(80)->pluck('front1');
                    $map = [];
                    foreach ($issues as $num) { $map[$num] = ($map[$num] ?? 0) + 1; }
                    arsort($map);
                    return array_slice($map, 0, 5, true);
                });
                $results = LottoDltRecommendation::whereNull('ip')
                    ->whereIn('front_1', array_keys($topAdv))
                    ->inRandomOrder()->take($take)->get();
                break;

            case 'connect':
                $consecutive = (int)($prefs['serial'] ?? 0);
                if ($consecutive <= 0) return response()->json(['success' => false, 'message' => '请选择连号个数'], 400);
                $results = LottoDltRecommendation::whereNull('ip')->where('consecutive_count', $consecutive)->inRandomOrder()->take($take)->get();
                break;

            case 'dan_only':
                $frontDan = (array)($prefs['front_dan'] ?? []);
                if (empty($frontDan) || count($frontDan) > 4) return response()->json(['success' => false, 'message' => '前区胆码数量 1-4 个'], 400);
                $query = LottoDltRecommendation::whereNull('ip');
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
                $query = LottoDltRecommendation::whereNull('ip');
                if (!empty($excludeSums)) $query->whereNotIn('front_sum', $excludeSums);
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            case 'history_span':
                $exclude = (array)$request->input('exclude', []);
                $query = LottoDltRecommendation::whereNull('ip');
                if (!empty($exclude)) $query->whereNotIn('span', $exclude);
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            case 'odd_even':
                if (empty($prefs['odd_even'])) return response()->json(['success' => false, 'message' => '请选择奇偶比'], 400);
                [$odd, $even] = explode(':', $prefs['odd_even']);
                $results = LottoDltRecommendation::whereNull('ip')->where('odd_count', (int)$odd)->where('even_count', (int)$even)->inRandomOrder()->take($take)->get();
                break;

            case 'first_last':
                $query = LottoDltRecommendation::whereNull('ip');
                if (!empty($prefs['first'])) $query->where('front_1', $prefs['first']);
                if (!empty($prefs['last'])) $query->where('front_5', $prefs['last']);
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            default:
                return response()->json(['success' => false, 'message' => '未知机选类型'], 400);
        }

        if ($results->isEmpty()) {
            return response()->json(['success' => false, 'message' => '没有符合条件的号码或数据未更新']);
        }

        // 3. 构建返回数据 (带特征分析)
        $randomData = $results->map(fn($row) => $service->buildRow($row));

        // 4. 绑定用户信息到推荐池 (标记已抽取)
        LottoDltRecommendation::whereIn('id', $results->pluck('id'))->update([
            'ip'      => $ip,
            'user_id' => $user->id,
            'mode'    => $type
        ]);

        // 5. ⭐ 自动保存到永久记录表
        $records = [];
        foreach ($results as $row) {
            $records[] = [
                'user_id'       => $user->id,
                'lottery_type'  => 'dlt',
                'issue'         => $row->issue,
                'front_numbers' => $row->front_numbers,
                'back_numbers'  => $row->back_numbers,
                'is_win'        => 0,
                'mode'          => $type,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }
        DB::table('user_lotto_selections')->insert($records);

        $response = [
            'success' => true,
            'data'    => $randomData,
            'remain'  => $remaining - $results->count(),
        ];

        if ($type === 'first_advantage') {
            $response['top'] = $topAdv ?? [];
        }

        return response()->json($response);
    }

    /**
     * 下载当前 IP 的号码
     */
    public function download(Request $request)
    {
        $ip = $request->ip();
        if (empty($ip)) return response()->json(['success'=>false,'message'=>'获取失败'],400);

        $list = LottoDltRecommendation::where('ip',$ip)->orderBy('id')->get();
        if ($list->isEmpty()) return response()->json(['success'=>false,'message'=>'暂无可下载数据'],404);

        $content = '';
        foreach($list as $i=>$row){
            $content .= sprintf("%02d. 前区:%s | 后区:%s\n", $i+1, $row->front_numbers, $row->back_numbers);
        }

        return response($content,200,[
            'Content-Type'=>'text/plain; charset=UTF-8',
            'Content-Disposition'=>'attachment; filename="dlt.txt"'
        ]);
    }

    /**
     * 大乐透号码分布分析
     */
    public function numberDistribution(Request $request)
    {
        $periods = (int) $request->input('periods', 50);
        $periods = min(max($periods, 1), 3000);

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
     * 获取上期开奖号码
     */
    public function lastIssue(Request $request)
    {
        $last = DB::table('dlt_lotto_history')->orderByDesc('id')->first();
        if (!$last) return response()->json(['success' => false, 'message' => '暂无历史开奖数据']);

        $redCold = json_decode($last->red_ball_omission, true) ?? [];
        $maxCold = $redCold ? max($redCold) : 0;
        $coldNumbers = [];
        foreach ($redCold as $num => $val) {
            if ($val === $maxCold && $val > 0) $coldNumbers[] = (int)$num;
        }

        return response()->json([
            'success' => true,
            'data' => [
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

    /**
     * 前区两码组合统计
     */
    public function pairStats()
    {
        $data = Cache::remember('dlt_pair_stats_100', 3600, function () {
            $rows = DB::table('dlt_lotto_history')->orderByDesc('issue')->limit(100)->get();
            $counts = [];
            foreach ($rows as $row) {
                $numbers = [$row->front1, $row->front2, $row->front3, $row->front4, $row->front5];
                sort($numbers);
                $len = count($numbers);
                for ($i = 0; $i < $len - 1; $i++) {
                    for ($j = $i + 1; $j < $len; $j++) {
                        $key = $numbers[$i] . ',' . $numbers[$j];
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                }
            }
            return $counts;
        });

        return response()->json(['data' => $data]);
    }
}