<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\LottoDltRecommendation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class DltController extends Controller
{
    /**
     * 通用大乐透机选接口
     */
    public function pick(Request $request)
    {
        $ip = $request->ip();

        if (empty($ip)) {
            return response()->json(['success' => false, 'message' => '获取失败'], 400);
        }

        // 每个 IP 最多 500 注
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
        $randomData = collect();


        switch ($type) {

            /**
             * =========================
             * 1️⃣ 普通机选（唯一权重模块）
             * =========================
             */
            case 'normal':

                $randomData = collect();

                // -------------------------
                // 获取上期开奖号码及特征（缓存1分钟）
                // -------------------------
                $lastIssue = Cache::remember('dlt_last_issue_features', 60, function() {
                    $last = DB::table('dlt_lotto_history')
                        ->orderByDesc('id')
                        ->first();

                    if (!$last) return null;

                    // 计算冷号
                    $redCold = json_decode($last->red_cold_json, true) ?? [];
                    $maxCold = $redCold ? max($redCold) : 0;
                    $coldNumbers = [];
                    foreach ($redCold as $num => $val) {
                        if ($val === $maxCold && $val > 0) $coldNumbers[] = (int)$num;
                    }

                    return [
                        'front_numbers'  => $last->front_numbers,
                        'back_numbers'   => $last->back_numbers,
                        'span'           => $last->span,
                        'front_sum'      => $last->front_sum,
                        'zone_ratio'     => explode(',', $last->zone_ratio), // 前区5个数分区间统计
                        'cold_numbers'   => $coldNumbers,
                        'continue_count' => $last->continue_count ?? 0
                    ];
                });

                if (!$lastIssue) {
                    return response()->json([
                        'success' => false,
                        'message' => '暂无历史开奖数据'
                    ]);
                }

                $lastSpan    = $lastIssue['span'];
                $lastSum     = $lastIssue['front_sum'];
                $lastZones   = $lastIssue['zone_ratio'];
                $coldNumbers = $lastIssue['cold_numbers'];

                // -------------------------
                // 获取推荐号码
                // -------------------------
                $results = LottoDltRecommendation::whereNull('ip')
                    ->whereIn('weight', [4,3,2])
                    ->inRandomOrder()
                    ->take($take)
                    ->select([
                        'id','front_numbers','back_numbers','span','front_sum',
                        'zone1_count','zone2_count','zone3_count',
                        'consecutive_count',
                        'front_1','front_2','front_3','front_4','front_5'
                    ])
                    ->get();

                if ($results->isEmpty()) {
                    foreach ([5,1] as $w) {
                        $results = LottoDltRecommendation::whereNull('ip')
                            ->where('weight', $w)
                            ->inRandomOrder()
                            ->take($take)
                            ->select([
                                'id','front_numbers','back_numbers','span','front_sum',
                                'zone1_count','zone2_count','zone3_count',
                                'consecutive_count',
                                'front_1','front_2','front_3','front_4','front_5'
                            ])
                            ->get();
                        if ($results->isNotEmpty()) break;
                    }
                }

                // -------------------------
                // 获取最近50期每个位置的号码出现次数（缓存1分钟）
                // -------------------------
                $posCounts = Cache::remember('dlt_last50_pos_counts', 60, function() {
                    $last50Issues = DB::table('dlt_lotto_history')
                        ->orderByDesc('id')
                        ->limit(50)
                        ->select(['front1','front2','front3','front4','front5'])
                        ->get()
                        ->toArray();

                    $counts = [];
                    for ($pos=1; $pos<=5; $pos++) $counts[$pos] = [];

                    foreach ($last50Issues as $issue) {
                        for ($pos=1; $pos<=5; $pos++) {
                            $num = $issue->{'front'.$pos};
                            if (!isset($counts[$pos][$num])) $counts[$pos][$num] = 0;
                            $counts[$pos][$num]++;
                        }
                    }
                    return $counts;
                });

                // -------------------------
                // 构建返回数据
                // -------------------------
                $randomData = $results->map(function($row) use ($lastSpan,$lastSum,$lastZones,$coldNumbers,$posCounts) {

                    $reds = array_map('intval', explode(',', $row->front_numbers));

                    // 本注号码中属于冷号的
                    $thisCold = array_values(array_intersect($reds, $coldNumbers));

                    // 区间比同上期
                    $zoneSame = isset($lastZones[0], $lastZones[1], $lastZones[2]) &&
                                $row->zone1_count == $lastZones[0] &&
                                $row->zone2_count == $lastZones[1] &&
                                $row->zone3_count == $lastZones[2];


                    // 位置近50期出现次数
                    $posAppear = [];
                    $lowPosNums = [];
                    for ($pos=1; $pos<=5; $pos++) {
                        $num = $row->{'front_'.$pos};
                        $count = $posCounts[$pos][$num] ?? 0;
                        $posAppear[] = $count;
                        if ($count===0) $lowPosNums[] = $num; // 黑色标记
                    }

                    return [
                        'id' => $row->id,
                        'front_numbers' => $row->front_numbers,
                        'back_numbers'  => $row->back_numbers,
                        'features' => [
                            'span_same'      => $row->span == $lastSpan,
                            'sum_same'       => $row->front_sum == $lastSum,
                            'zone_same'      => $zoneSame,
                            'cold_numbers'   => $thisCold,
                            'continue_count' => $row->consecutive_count,
                            'pos_appear'     => $posAppear,
                            'low_pos_nums'   => $lowPosNums
                        ]
                    ];
                });

            break;



            /**
             * =========================
             * 2️⃣ 首红优势（不使用权重）
             * =========================
             */
            case 'first_advantage':
                $randomData = LottoDltRecommendation::whereNull('ip')
                    ->whereBetween('front_1', [1,7])
                    ->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 3️⃣ 连号机选（不使用权重）
             * =========================
             */
            case 'connect':
                $consecutive = (int)($prefs['serial'] ?? 0);
                if ($consecutive <= 0) {
                    return response()->json(['success'=>false,'message'=>'请选择连号个数'],400);
                }

                $randomData = LottoDltRecommendation::whereNull('ip')
                    ->where('consecutive_count', $consecutive)
                    ->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 4️⃣ 前区胆码（不使用权重）
             * =========================
             */
            case 'dan_only':
                $frontDan = (array)($prefs['front_dan'] ?? []);
                if (empty($frontDan) || count($frontDan) > 4) {
                    return response()->json([
                        'success'=>false,
                        'message'=>'前区胆码数量 1-4 个'
                    ],400);
                }

                $query = LottoDltRecommendation::whereNull('ip');
                foreach ($frontDan as $num) {
                    $query->where(function ($q) use ($num) {
                        $q->orWhere('front_1',$num)
                          ->orWhere('front_2',$num)
                          ->orWhere('front_3',$num)
                          ->orWhere('front_4',$num)
                          ->orWhere('front_5',$num);
                    });
                }

                $randomData = $query->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 5️⃣ 排除历史和值（不使用权重）
             * =========================
             */
            case 'history_sum':
                $excludeCount = (int)$request->input('exclude',0);
                $excludeSums = [];

                if ($excludeCount > 0) {
                    $excludeSums = DB::table('dlt_lotto_history')
                        ->orderByDesc('issue')
                        ->limit($excludeCount)
                        ->pluck('front_sum')
                        ->toArray();
                }

                $query = LottoDltRecommendation::whereNull('ip');
                if (!empty($excludeSums)) {
                    $query->whereNotIn('front_sum',$excludeSums);
                }

                $randomData = $query->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 6️⃣ 排除历史跨度（不使用权重）
             * =========================
             */
            case 'history_span':
                $exclude = (array)$request->input('exclude',[]);
                $query = LottoDltRecommendation::whereNull('ip');

                if (!empty($exclude)) {
                    $query->whereNotIn('span',$exclude);
                }

                $randomData = $query->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 7️⃣ 奇偶比（不使用权重）
             * =========================
             */
            case 'odd_even':
                if (empty($prefs['odd_even'])) {
                    return response()->json(['success'=>false,'message'=>'请选择奇偶比'],400);
                }
                [$odd,$even] = explode(':',$prefs['odd_even']);

                $randomData = LottoDltRecommendation::whereNull('ip')
                    ->where('odd_count',(int)$odd)
                    ->where('even_count',(int)$even)
                    ->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 8️⃣ 首尾号（不使用权重）
             * =========================
             */
            case 'first_last':
                $query = LottoDltRecommendation::whereNull('ip');
                if (!empty($prefs['first'])) $query->where('front_1',$prefs['first']);
                if (!empty($prefs['last']))  $query->where('front_5',$prefs['last']);

                $randomData = $query->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            default:
                return response()->json([
                    'success'=>false,
                    'message'=>'未知机选类型'
                ],400);
        }

        if ($randomData->isEmpty()) {
            return response()->json([
                'success'=>false,
                'message'=>'没有符合条件的号码'
            ]);
        }

        // 绑定 IP
        LottoDltRecommendation::whereIn(
            'id',
            $randomData->pluck('id')->toArray()
        )->update(['ip'=>$ip, 'mode' => $type]);

        return response()->json([
            'success'=>true,
            'data'=>$randomData,
            'remain'=>$remaining - $randomData->count(),
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
        $redCold = json_decode($last->red_cold_json, true) ?? [];
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
}
