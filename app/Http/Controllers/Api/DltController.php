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


   public function pick(Request $request)
    {
        $ip = $request->ip();

        if (empty($ip)) {
            return response()->json(['success' => false, 'message' => '获取失败'], 400);
        }

        // IP限制
        $count = LottoDltRecommendation::where('ip', $ip)->count();
        $maxPerIp = 500;
        $remaining = $maxPerIp - $count;

        if ($remaining <= 0) {
            return response()->json([
                'success' => false,
                'message' => '随机次数已用完',
                'remain' => 0
            ]);
        }

        $take  = min(5, $remaining);
        $type  = $request->input('type', 'normal');
        $prefs = $request->input('prefs', []);

        $service = new DltLotteryFeatureService();

        switch ($type) {

            /**
             * =========================
             * normal
             * =========================
             */
            case 'normal':

                $results = LottoDltRecommendation::whereNull('ip')
                    ->inRandomOrder()
                    ->take($take)
                    ->get();

                $randomData = $results->map(fn($row) =>
                    $service->buildRow($row)
                );

            break;

            /**
             * =========================
             * first_advantage
             * =========================
             */
            case 'first_advantage':

                $topAdv = Cache::remember('dlt_first_adv_top5', 60, function () {

                    $issues = DB::table('dlt_lotto_history')
                        ->orderByDesc('id')
                        ->limit(80)
                        ->pluck('front1');

                    $map = [];

                    foreach ($issues as $num) {
                        $map[$num] = ($map[$num] ?? 0) + 1;
                    }

                    arsort($map);

                    return array_slice($map, 0, 5, true);
                });

                $topFirstNumbers = array_keys($topAdv);

                if (empty($topFirstNumbers)) {
                    return response()->json([
                        'success' => false,
                        'message' => '暂无首红统计'
                    ]);
                }

                $results = LottoDltRecommendation::whereNull('ip')
                    ->whereIn('front_1', $topFirstNumbers)
                    ->inRandomOrder()
                    ->take($take)
                    ->get();

                if ($results->isEmpty()) {
                    return response()->json([
                        'success' => false,
                        'message' => '暂无推荐号码'
                    ]);
                }

                $randomData = $results->map(fn($row) =>
                    $service->buildRow($row)
                );

                return response()->json([
                    'success' => true,
                    'data' => $randomData,
                    'top'  => $topAdv
                ]);

            break;

            /**
             * =========================
             * connect
             * =========================
             */
            case 'connect':

                $consecutive = (int)($prefs['serial'] ?? 0);

                if ($consecutive <= 0) {
                    return response()->json([
                        'success' => false,
                        'message' => '请选择连号个数'
                    ], 400);
                }

                $results = LottoDltRecommendation::whereNull('ip')
                    ->where('consecutive_count', $consecutive)
                    ->inRandomOrder()
                    ->take($take)
                    ->get();

                $randomData = $results->map(fn($row) =>
                    $service->buildRow($row)
                );

            break;

            /**
             * =========================
             * dan_only
             * =========================
             */
            case 'dan_only':

                $frontDan = (array)($prefs['front_dan'] ?? []);

                if (empty($frontDan) || count($frontDan) > 4) {
                    return response()->json([
                        'success' => false,
                        'message' => '前区胆码数量 1-4 个'
                    ], 400);
                }

                $query = LottoDltRecommendation::whereNull('ip');

                foreach ($frontDan as $num) {
                    $query->where(function ($q) use ($num) {
                        $q->orWhere('front_1', $num)
                        ->orWhere('front_2', $num)
                        ->orWhere('front_3', $num)
                        ->orWhere('front_4', $num)
                        ->orWhere('front_5', $num);
                    });
                }

                $results = $query->inRandomOrder()
                    ->take($take)
                    ->get();

                $randomData = $results->map(fn($row) =>
                    $service->buildRow($row)
                );

            break;

            /**
             * =========================
             * history_sum
             * =========================
             */
            case 'history_sum':

                $excludeCount = (int)$request->input('exclude', 0);

                $excludeSums = $excludeCount > 0
                    ? DB::table('dlt_lotto_history')
                        ->orderByDesc('issue')
                        ->limit($excludeCount)
                        ->pluck('front_sum')
                        ->toArray()
                    : [];

                $query = LottoDltRecommendation::whereNull('ip');

                if (!empty($excludeSums)) {
                    $query->whereNotIn('front_sum', $excludeSums);
                }

                $results = $query->inRandomOrder()
                    ->take($take)
                    ->get();

                $randomData = $results->map(fn($row) =>
                    $service->buildRow($row)
                );

            break;

            /**
             * =========================
             * history_span
             * =========================
             */
            case 'history_span':

                $exclude = (array)$request->input('exclude', []);

                $query = LottoDltRecommendation::whereNull('ip');

                if (!empty($exclude)) {
                    $query->whereNotIn('span', $exclude);
                }

                $results = $query->inRandomOrder()
                    ->take($take)
                    ->get();

                $randomData = $results->map(fn($row) =>
                    $service->buildRow($row)
                );

            break;

            /**
             * =========================
             * odd_even
             * =========================
             */
            case 'odd_even':

                if (empty($prefs['odd_even'])) {
                    return response()->json([
                        'success' => false,
                        'message' => '请选择奇偶比'
                    ], 400);
                }

                [$odd, $even] = explode(':', $prefs['odd_even']);

                $results = LottoDltRecommendation::whereNull('ip')
                    ->where('odd_count', (int)$odd)
                    ->where('even_count', (int)$even)
                    ->inRandomOrder()
                    ->take($take)
                    ->get();

                $randomData = $results->map(fn($row) =>
                    $service->buildRow($row)
                );

            break;

            /**
             * =========================
             * first_last
             * =========================
             */
            case 'first_last':

                $query = LottoDltRecommendation::whereNull('ip');

                if (!empty($prefs['first'])) {
                    $query->where('front_1', $prefs['first']);
                }

                if (!empty($prefs['last'])) {
                    $query->where('front_5', $prefs['last']);
                }

                $results = $query->inRandomOrder()
                    ->take($take)
                    ->get();

                $randomData = $results->map(fn($row) =>
                    $service->buildRow($row)
                );

            break;

            default:
                return response()->json([
                    'success' => false,
                    'message' => '未知机选类型'
                ], 400);
        }

        if ($randomData->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => '没有符合条件的号码'
            ]);
        }

        LottoDltRecommendation::whereIn(
            'id',
            $randomData->pluck('id')->toArray()
        )->update([
            'ip'   => $ip,
            'mode' => $type
        ]);

        return response()->json([
            'success' => true,
            'data' => $randomData,
            'remain' => $remaining - $randomData->count(),
        ]);
    }

    /**
     * 下载当前 IP 的号码
     */
    public function download(Request $request)
    {
        $ip = $request->ip();

        if (empty($ip)) {
            return response()->json(['success'=>false,'message'=>'获取失败'],400);
        }

        $list = LottoDltRecommendation::where('ip',$ip)
            ->orderBy('id')
            ->get();

        if ($list->isEmpty()) {
            return response()->json(['success'=>false,'message'=>'暂无可下载数据'],404);
        }

        $content = '';
        foreach($list as $i=>$row){
            $content .= sprintf(
                "%02d. 前区:%s | 后区:%s\n",
                $i+1,
                $row->front_numbers,
                $row->back_numbers
            );
        }

        return response($content,200,[
            'Content-Type'=>'text/plain; charset=UTF-8',
            'Content-Disposition'=>'attachment; filename="dlt.txt"'
        ]);
    }

    /**
     * 大乐透号码分布分析
     * 前区 5 位 + 后区 2 位
     */
    public function numberDistribution(Request $request)
    {
        $periods = (int) $request->input('periods', 50);

        if ($periods <= 0) {
            return response()->json([
                'code' => 400,
                'message' => '期数不合法'
            ]);
        }

        if ($periods > 3000) {
            $periods = 3000;
        }

        // 最近 N 期
        $history = DB::table('dlt_lotto_history')
            ->orderByDesc('issue')
            ->limit($periods)
            ->get([
                'front1','front2','front3','front4','front5',
                'back1','back2'
            ]);

        if ($history->isEmpty()) {
            return response()->json([
                'code' => 200,
                'data' => [
                    'front' => [[], [], [], [], []],
                    'back'  => [[], []]
                ]
            ]);
        }

        // 初始化统计容器
        $front = array_fill(0, 5, []);
        $back  = array_fill(0, 2, []);

        foreach ($history as $row) {

            // 前区
            for ($i = 1; $i <= 5; $i++) {
                $num = (int) $row->{'front'.$i};
                if (!isset($front[$i-1][$num])) {
                    $front[$i-1][$num] = 0;
                }
                $front[$i-1][$num]++;
            }

            // 后区
            for ($i = 1; $i <= 2; $i++) {
                $num = (int) $row->{'back'.$i};
                if (!isset($back[$i-1][$num])) {
                    $back[$i-1][$num] = 0;
                }
                $back[$i-1][$num]++;
            }
        }

        // 整理成前端需要的格式
        $format = function ($arr) {
            $out = [];
            foreach ($arr as $num => $count) {
                $out[] = [
                    'number' => $num,
                    'count'  => $count
                ];
            }
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
     * 新增接口：获取上期开奖号码
     */
    public function lastIssue(Request $request)
    {
        // 获取最新一期的前一期开奖结果
        $last = DB::table('dlt_lotto_history')
            ->orderByDesc('id')
            ->first();

        if (!$last) {
            return response()->json([
                'success' => false,
                'message' => '暂无历史开奖数据'
            ]);
        }

        // 解析冷号
        $redCold = json_decode($last->red_ball_omission, true) ?? [];
        $maxCold = $redCold ? max($redCold) : 0;
        $coldNumbers = [];
        foreach ($redCold as $num => $val) {
            if ($val === $maxCold && $val > 0) {
                $coldNumbers[] = (int)$num;
            }
        }

        $data = [
            'front_numbers' => $last->front_numbers,
            'back_numbers'  => $last->back_numbers,
            'features' => [
                'cold_numbers'   => $coldNumbers,
                'continue_count' => $last->continue_count ?? 0
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }


    /**
     * 后区组合出现次数统计
     * 12选2 = 66组合
     */
    public function backComboStats(Request $request)
    {
        $periods = (int) $request->input('periods', 660);

        if ($periods <= 0) {
            $periods = 660;
        }

        if ($periods > 3000) {
            $periods = 3000;
        }

        // 获取最近N期后区
        $history = DB::table('dlt_lotto_history')
            ->orderByDesc('issue')
            ->limit($periods)
            ->get(['back1','back2']);

        if ($history->isEmpty()) {
            return response()->json([
                'code' => 200,
                'data' => []
            ]);
        }

        $stats = [];

        foreach ($history as $row) {

            $a = min($row->back1, $row->back2);
            $b = max($row->back1, $row->back2);

            $key = sprintf('%02d-%02d', $a, $b);

            if (!isset($stats[$key])) {
                $stats[$key] = [
                    'combo' => $key,
                    'n1' => $a,
                    'n2' => $b,
                    'count' => 0
                ];
            }

            $stats[$key]['count']++;
        }

        // 排序（出现次数高的在前）
        usort($stats, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return response()->json([
            'code' => 200,
            'data' => array_values($stats)
        ]);
    }


    public function pairStats()
    {
        return Cache::remember('dlt_pair_stats_100', 3600, function () {

            // 1️⃣ 取最近100期
            $rows = DB::table('dlt_lotto_history')
                ->orderByDesc('issue')
                ->limit(100)
                ->get();

            $counts = [];

            // 2️⃣ 遍历每一期
            foreach ($rows as $row) {

                $numbers = [
                    $row->front1,
                    $row->front2,
                    $row->front3,
                    $row->front4,
                    $row->front5
                ];

                sort($numbers); // 排序保证组合一致

                $len = count($numbers);

                // 3️⃣ 两两组合
                for ($i = 0; $i < $len - 1; $i++) {
                    for ($j = $i + 1; $j < $len; $j++) {

                        $key = $numbers[$i] . ',' . $numbers[$j];

                        if (!isset($counts[$key])) {
                            $counts[$key] = 0;
                        }

                        $counts[$key]++;
                    }
                }
            }

            // 4️⃣ 返回数据
            return response()->json([
                'data' => $counts
            ]);

        });
    }
   
    
}
