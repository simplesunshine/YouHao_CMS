<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\LottoSsqRecommendation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class SsqController extends Controller
{
    /**
     * 通用机选接口
     */
    public function pick(Request $request)
    {
        $ip = $request->ip();

        if (empty($ip)) {
            return response()->json(['success' => false, 'message' => '获取失败'], 400);
        }

        // 每个 IP 最多 500 注
        $count = LottoSsqRecommendation::where('ip', $ip)->count();
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
             * 1️⃣ 普通机选（唯一加权模块）
             * =========================
             */
            case 'normal':

                $randomData = collect();

                // -------------------------
                // 上期特征（缓存）
                // -------------------------
                $lastIssue = Cache::remember('ssq_last_issue_features', 60, function() {

                    $last = DB::table('ssq_lotto_history')
                        ->orderByDesc('id')
                        ->first();

                    if (!$last) return null;

                    // ✅ 使用 next_red_max_miss_json 作为冷号和下期最大遗漏号码来源
                    $nextMaxMiss = json_decode($last->next_red_max_miss_json, true) ?? [];

                    return [
                        'span'         => $last->span,
                        'front_sum'    => $last->front_sum,
                        'zone_ratio'   => explode(',', $last->zone_ratio),
                        'cold_numbers' => $nextMaxMiss, // 灰色标注
                    ];
                });

                if (!$lastIssue) {
                    return response()->json([
                        'success' => false,
                        'message' => '暂无历史开奖数据'
                    ]);
                }

                $lastSpan     = $lastIssue['span'];
                $lastSum      = $lastIssue['front_sum'];
                $lastZones    = $lastIssue['zone_ratio'];
                $coldNumbers  = $lastIssue['cold_numbers'];

                // -------------------------
                // 高频两码组合（已缓存）
                // -------------------------
                $hotPairs = $this->getHotPairs(6);

                // -------------------------
                // 推荐号码
                // -------------------------
                $results = LottoSsqRecommendation::whereNull('ip')
                    ->whereIn('weight', [0,1,2,3,4,5])
                    ->inRandomOrder()
                    ->take($take)
                    ->select([
                        'id','front_numbers','back_numbers','span','front_sum',
                        'zone1_count','zone2_count','zone3_count','consecutive_count',
                        'front_1','front_2','front_3','front_4','front_5','front_6'
                    ])
                    ->get();

                if ($results->isEmpty()) {
                    foreach ([5,2,1] as $w) {
                        $results = LottoSsqRecommendation::whereNull('ip')
                            ->where('weight', $w)
                            ->inRandomOrder()
                            ->take($take)
                            ->select([
                                'id','front_numbers','back_numbers','span','front_sum',
                                'zone1_count','zone2_count','zone3_count','consecutive_count',
                                'front_1','front_2','front_3','front_4','front_5','front_6'
                            ])
                            ->get();
                        if ($results->isNotEmpty()) break;
                    }
                }

                // -------------------------
                // 位置统计（缓存）
                // -------------------------
                $posCounts = Cache::remember('ssq_last80_pos_counts', 60, function() {

                    $rows = DB::table('ssq_lotto_history')
                        ->orderByDesc('id')
                        ->limit(80)
                        ->select(['front1','front2','front3','front4','front5','front6'])
                        ->get();

                    $counts = [];
                    for ($i = 1; $i <= 6; $i++) $counts[$i] = [];

                    foreach ($rows as $row) {
                        for ($i = 1; $i <= 6; $i++) {
                            $num = $row->{'front'.$i};
                            $counts[$i][$num] = ($counts[$i][$num] ?? 0) + 1;
                        }
                    }

                    return $counts;
                });

                // -------------------------
                // 构建结果
                // -------------------------
                $randomData = $results->map(function($row) use (
                    $lastSpan, $lastSum, $lastZones,
                    $coldNumbers, $posCounts, $hotPairs
                ) {

                    $reds = array_map('intval', explode(',', $row->front_numbers));
                    sort($reds);

                    // -------------------------
                    // 高频两码命中
                    // -------------------------
                    $pairHit = false;
                    $pairScore = 0;
                    $hitPairs = [];
                    $len = count($reds);

                    for ($i = 0; $i < $len - 1; $i++) {
                        for ($j = $i + 1; $j < $len; $j++) {
                            $key = $reds[$i] . ',' . $reds[$j];
                            if (isset($hotPairs[$key])) {
                                $pairHit = true;
                                $pairScore += $hotPairs[$key];
                                $hitPairs[] = [
                                    'pair'  => $key,
                                    'count' => $hotPairs[$key]
                                ];
                            }
                        }
                    }

                    // -------------------------
                    // 冷号（灰色）直接用 next_red_max_miss_json
                    // -------------------------
                    $thisCold = array_values(array_intersect($reds, $coldNumbers));

                    // -------------------------
                    // 区间比
                    // -------------------------
                    $zoneSame = (
                        $row->zone1_count == $lastZones[0] &&
                        $row->zone2_count == $lastZones[1] &&
                        $row->zone3_count == $lastZones[2]
                    );

                    // -------------------------
                    // 位置出现次数
                    // -------------------------
                    $posAppear = [];
                    $lowPosNums = [];

                    for ($pos = 1; $pos <= 6; $pos++) {
                        $num = $row->{'front_'.$pos};
                        $count = $posCounts[$pos][$num] ?? 0;
                        $posAppear[] = $count;
                        if ($count === 0) {
                            $lowPosNums[] = $num;
                        }
                    }

                    return [
                        'id' => $row->id,
                        'front_numbers' => $row->front_numbers,
                        'back_numbers'  => $row->back_numbers,
                        'features' => [
                            'span_same'   => $row->span == $lastSpan,
                            'sum_same'    => $row->front_sum == $lastSum,
                            'zone_same'   => $zoneSame,
                            'cold_numbers'=> $thisCold,
                            'pos_appear'  => $posAppear,
                            'low_pos_nums'=> $lowPosNums,
                            'pair_hit'    => $pairHit,
                            'pair_score'  => $pairScore,
                            'hit_pairs'   => $hitPairs
                        ]
                    ];
                });

            break;


            /**
             * =========================
             * 4️⃣ 胆码机选（加权版 + 高频两码组合）
             * =========================
             */
            case 'dan_only':
                $dan = (array)($prefs['dan'] ?? []);
                if (count($dan) < 1 || count($dan) > 5) {
                    return response()->json([
                        'success' => false,
                        'message' => '胆码数量必须 1–5 个'
                    ], 400);
                }

                // -------------------------
                // 获取上期开奖号码及特征
                // -------------------------
                $lastIssue = DB::table('ssq_lotto_history')
                    ->orderByDesc('id')
                    ->first();

                if (!$lastIssue) {
                    return response()->json([
                        'success' => false,
                        'message' => '暂无历史开奖数据'
                    ]);
                }

                $lastSpan  = $lastIssue->span;
                $lastSum   = $lastIssue->front_sum;
                $lastZones = explode(',', $lastIssue->zone_ratio);

                // -------------------------
                // ⚡ 使用 next_red_max_miss_json 作为冷号
                $nextMiss = json_decode($lastIssue->next_red_max_miss_json, true) ?? [];
                $coldNumbers = array_values($nextMiss); // 灰色冷号

                // -------------------------
                // 高频两码组合（缓存）
                $hotPairs = $this->getHotPairs(6);

                // -------------------------
                // 获取最近80期每个位置的号码出现次数
                $posCounts = Cache::remember('ssq_last80_pos_counts', 60, function() {
                    $last80Issues = DB::table('ssq_lotto_history')
                        ->orderByDesc('id')
                        ->limit(80)
                        ->select(['front1','front2','front3','front4','front5','front6'])
                        ->get()
                        ->toArray();

                    $counts = [];
                    for ($pos = 1; $pos <= 6; $pos++) $counts[$pos] = [];
                    foreach ($last80Issues as $issue) {
                        for ($pos = 1; $pos <= 6; $pos++) {
                            $num = $issue->{'front'.$pos};
                            $counts[$pos][$num] = ($counts[$pos][$num] ?? 0) + 1;
                        }
                    }
                    return $counts;
                });

                // -------------------------
                // 查询符合胆码条件的号码 + 权重 0–5
                $query = LottoSsqRecommendation::whereNull('ip')
                    ->whereIn('weight',[0,1,2,3,4,5]);

                foreach ($dan as $num) {
                    $query->where(function($q) use ($num) {
                        $q->orWhere('front_1',$num)
                        ->orWhere('front_2',$num)
                        ->orWhere('front_3',$num)
                        ->orWhere('front_4',$num)
                        ->orWhere('front_5',$num)
                        ->orWhere('front_6',$num);
                    });
                }

                $results = $query->inRandomOrder()
                    ->take($take)
                    ->select([
                        'id','front_numbers','back_numbers','span','front_sum',
                        'zone1_count','zone2_count','zone3_count','consecutive_count',
                        'front_1','front_2','front_3','front_4','front_5','front_6'
                    ])
                    ->get();

                // 不够再补权重5
                if ($results->count() < $take) {
                    $remaining = $take - $results->count();
                    $extra = LottoSsqRecommendation::whereNull('ip')->whereIn('weight',[5,0,1,2]);
                    foreach ($dan as $num) {
                        $extra->where(function($q) use ($num) {
                            $q->orWhere('front_1',$num)
                            ->orWhere('front_2',$num)
                            ->orWhere('front_3',$num)
                            ->orWhere('front_4',$num)
                            ->orWhere('front_5',$num)
                            ->orWhere('front_6',$num);
                        });
                    }
                    $results = $results->merge(
                        $extra->inRandomOrder()
                            ->take($remaining)
                            ->select([
                                'id','front_numbers','back_numbers','span','front_sum',
                                'zone1_count','zone2_count','zone3_count','consecutive_count',
                                'front_1','front_2','front_3','front_4','front_5','front_6'
                            ])
                            ->get()
                    );
                }

                // -------------------------
                // 构建返回数据
                $randomData = $results->map(function($row) use ($lastSpan,$lastSum,$lastZones,$coldNumbers,$posCounts,$hotPairs) {
                    $reds = array_map('intval', explode(',', $row->front_numbers));

                    // 冷号（灰色）
                    $thisCold = array_values(array_intersect($reds,$coldNumbers));

                    // 区间比
                    $zoneSame = ($row->zone1_count == $lastZones[0] &&
                                $row->zone2_count == $lastZones[1] &&
                                $row->zone3_count == $lastZones[2]);

                    // 位置出现次数 & 低出现号码（黑色）
                    $posAppear = [];
                    $lowPosNums = [];
                    for ($pos=1; $pos<=6; $pos++){
                        $num = $reds[$pos-1];
                        $count = $posCounts[$pos][$num] ?? 0;
                        $posAppear[] = $count;

                        if ($count === 0) $lowPosNums[] = $num;
                    }

                    // 高频组合命中
                    $pairHit = false;
                    $pairScore = 0;
                    $hitPairs = [];
                    $len = count($reds);
                    for ($i=0; $i<$len-1; $i++){
                        for ($j=$i+1; $j<$len; $j++){
                            $key = $reds[$i].','.$reds[$j];
                            if(isset($hotPairs[$key])){
                                $pairHit = true;
                                $pairScore += $hotPairs[$key];
                                $hitPairs[] = ['pair'=>$key,'count'=>$hotPairs[$key]];
                            }
                        }
                    }

                    return [
                        'id'=>$row->id,
                        'front_numbers'=>$row->front_numbers,
                        'back_numbers'=>$row->back_numbers,
                        'features'=>[
                            'span_same'=>$row->span == $lastSpan,
                            'sum_same'=>$row->front_sum == $lastSum,
                            'zone_same'=>$zoneSame,
                            'cold_numbers'=>$thisCold,    // 灰色
                            'continue_count'=>$row->consecutive_count,
                            'pos_appear'=>$posAppear,
                            'low_pos_nums'=>$lowPosNums,  // 黑色
                            'pair_hit'=>$pairHit,
                            'pair_score'=>$pairScore,
                            'hit_pairs'=>$hitPairs
                        ]
                    ];
                });


            break;


            /**
             * =========================
             * 2️⃣ 首红优势（已简化 + 加入高频两码）
             * =========================
             */
            case 'first_advantage':

                // =========================
                // 上期数据（统一缓存）
                // =========================
                $lastIssue = Cache::remember('ssq_last_issue_features', 60, function() {

                    $last = DB::table('ssq_lotto_history')
                        ->orderByDesc('id')
                        ->first();

                    if (!$last) return null;

                    // 使用 next_red_max_miss_json 作为冷号来源
                    $coldNumbers = json_decode($last->next_red_max_miss_json, true) ?? [];

                    return [
                        'span'        => $last->span,
                        'front_sum'   => $last->front_sum,
                        'zone_ratio'  => explode(',', $last->zone_ratio),
                        'cold_numbers'=> $coldNumbers
                    ];
                });

                if (!$lastIssue) {
                    return response()->json([
                        'success' => false,
                        'message' => '暂无历史开奖数据'
                    ]);
                }

                // =========================
                // 近80期首红统计
                // =========================
                $firstCounts = Cache::remember('ssq_last80_first_counts', 60, function() {

                    return DB::table('ssq_lotto_history')
                        ->orderByDesc('id')
                        ->limit(80)
                        ->pluck('front1')
                        ->countBy()
                        ->toArray();
                });

                arsort($firstCounts);

                $topLimit = 5;

                $strongFirst = array_slice(array_keys($firstCounts), 0, $topLimit);

                $topFirstDisplay = array_slice($firstCounts, 0, $topLimit, true);

                if (empty($strongFirst)) {
                    return response()->json([
                        'success' => false,
                        'message' => '暂无首红数据'
                    ]);
                }

                // =========================
                // 高频两码组合（新增）
                // =========================
                $hotPairs = $this->getHotPairs(6);

                // =========================
                // 推荐查询（去权重逻辑）
                // =========================
                $results = LottoSsqRecommendation::whereNull('ip')
                    ->whereIn('front_1', $strongFirst)
                    ->inRandomOrder()
                    ->take($take)
                    ->select([
                        'id','front_numbers','back_numbers','span','front_sum',
                        'zone1_count','zone2_count','zone3_count','consecutive_count',
                        'front_1','front_2','front_3','front_4','front_5','front_6'
                    ])
                    ->get();

                // =========================
                // 位置统计
                // =========================
                $posCounts = Cache::remember('ssq_last80_pos_counts', 60, function() {

                    $rows = DB::table('ssq_lotto_history')
                        ->orderByDesc('id')
                        ->limit(80)
                        ->select(['front1','front2','front3','front4','front5','front6'])
                        ->get();

                    $counts = [];
                    for ($i = 1; $i <= 6; $i++) {
                        $counts[$i] = [];
                    }

                    foreach ($rows as $row) {
                        for ($i = 1; $i <= 6; $i++) {
                            $num = $row->{'front'.$i};
                            $counts[$i][$num] = ($counts[$i][$num] ?? 0) + 1;
                        }
                    }

                    return $counts;
                });

                // =========================
                // 构建返回数据（加入高频两码）
                // =========================
                $randomData = $results->map(function($row) use ($lastIssue, $posCounts, $hotPairs) {

                    $reds = array_map('intval', explode(',', $row->front_numbers));

                    // 冷号
                    $thisCold = array_values(array_intersect($reds, $lastIssue['cold_numbers']));

                    // 区间比
                    $zoneSame = (
                        $row->zone1_count == $lastIssue['zone_ratio'][0] &&
                        $row->zone2_count == $lastIssue['zone_ratio'][1] &&
                        $row->zone3_count == $lastIssue['zone_ratio'][2]
                    );

                    // 位置统计 + 黑号
                    $posAppear = [];
                    $lowPosNums = [];

                    for ($pos = 1; $pos <= 6; $pos++) {
                        $num = $row->{'front_'.$pos};
                        $count = $posCounts[$pos][$num] ?? 0;

                        $posAppear[] = $count;

                        if ($count === 0) {
                            $lowPosNums[] = $num;
                        }
                    }

                    // =========================
                    // 高频两码命中（新增核心逻辑）
                    // =========================
                    $pairHit = false;
                    $pairScore = 0;
                    $hitPairs = [];
                    $len = count($reds);

                    for ($i = 0; $i < $len - 1; $i++) {
                        for ($j = $i + 1; $j < $len; $j++) {
                            $key = $reds[$i] . ',' . $reds[$j];

                            if (isset($hotPairs[$key])) {
                                $pairHit = true;
                                $pairScore += $hotPairs[$key];
                                $hitPairs[] = [
                                    'pair'  => $key,
                                    'count' => $hotPairs[$key]
                                ];
                            }
                        }
                    }

                    return [
                        'id' => $row->id,
                        'front_numbers' => $row->front_numbers,
                        'back_numbers'  => $row->back_numbers,
                        'features' => [
                            'span_same'    => $row->span == $lastIssue['span'],
                            'sum_same'     => $row->front_sum == $lastIssue['front_sum'],
                            'zone_same'    => $zoneSame,
                            'cold_numbers' => $thisCold,
                            'continue_count' => $row->consecutive_count,
                            'pos_appear'   => $posAppear,
                            'low_pos_nums' => $lowPosNums,

                            // 高频两码
                            'pair_hit'     => $pairHit,
                            'pair_score'   => $pairScore,
                            'hit_pairs'    => $hitPairs
                        ]
                    ];
                });
            break;

            /**
             * =========================
             * 3️⃣ 连号（不加权）
             * =========================
             */
            case 'connect':
                $consecutive = (int)($prefs['serial'] ?? 0);
                if ($consecutive <= 0) {
                    return response()->json(['success'=>false,'message'=>'请选择连号个数'],400);
                }

                $randomData = LottoSsqRecommendation::whereNull('ip')
                    ->where('consecutive_count', $consecutive)
                    ->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            


            /**
             * =========================
             * 5️⃣ 排除历史和值（不加权）
             * =========================
             */
            case 'history_sum':

                $excludeCount = (int)$request->input('exclude',0);
                $excludeSums = [];

                if ($excludeCount > 0) {
                    $excludeSums = DB::table('ssq_lotto_history')
                        ->orderByDesc('issue')
                        ->limit($excludeCount)
                        ->pluck('front_sum')
                        ->toArray();
                }

                // =========================
                // 基础查询
                // =========================
                $query = LottoSsqRecommendation::whereNull('ip');

                if (!empty($excludeSums)) {
                    $query->whereNotIn('front_sum', $excludeSums);
                }

                $results = $query->inRandomOrder()
                    ->take($take)
                    ->select([
                        'id','front_numbers','back_numbers','span','front_sum',
                        'zone1_count','zone2_count','zone3_count','consecutive_count',
                        'front_1','front_2','front_3','front_4','front_5','front_6'
                    ])
                    ->get();

                if ($results->isEmpty()) {
                    return response()->json([
                        'success' => false,
                        'message' => '没有符合条件的号码'
                    ]);
                }

                // =========================
                // 上期特征（统一逻辑）
                // =========================
                $last = Cache::remember('ssq_last_issue_features', 60, function () {
                    $last = DB::table('ssq_lotto_history')
                        ->orderByDesc('id')
                        ->first();

                    if (!$last) return null;

                    return [
                        'span' => $last->span,
                        'front_sum' => $last->front_sum,
                        'zone_ratio' => explode(',', $last->zone_ratio),
                        'cold_numbers' => json_decode($last->next_red_max_miss_json, true) ?? []
                    ];
                });

                if (!$last) {
                    return response()->json([
                        'success' => false,
                        'message' => '暂无历史数据'
                    ]);
                }

                $coldNumbers = $last['cold_numbers'];
                $lastZones = $last['zone_ratio'];
                $lastSpan = $last['span'];
                $lastSum = $last['front_sum'];

                // =========================
                // 高频两码
                // =========================
                $hotPairs = $this->getHotPairs(6);

                // =========================
                // 位置统计
                // =========================
                $posCounts = Cache::remember('ssq_last80_pos_counts', 60, function () {
                    $rows = DB::table('ssq_lotto_history')
                        ->orderByDesc('id')
                        ->limit(80)
                        ->select(['front1','front2','front3','front4','front5','front6'])
                        ->get();

                    $counts = [];
                    for ($i=1;$i<=6;$i++) $counts[$i] = [];

                    foreach ($rows as $row) {
                        for ($i=1;$i<=6;$i++) {
                            $num = $row->{'front'.$i};
                            $counts[$i][$num] = ($counts[$i][$num] ?? 0) + 1;
                        }
                    }

                    return $counts;
                });

                // =========================
                // 构建返回（关键）
                // =========================
                $randomData = $results->map(function($row) use (
                    $lastSpan,$lastSum,$lastZones,$coldNumbers,$posCounts,$hotPairs
                ) {

                    $reds = array_map('intval', explode(',', $row->front_numbers));

                    // 冷号
                    $thisCold = array_values(array_intersect($reds, $coldNumbers));

                    // 区间比
                    $zoneSame = (
                        $row->zone1_count == $lastZones[0] &&
                        $row->zone2_count == $lastZones[1] &&
                        $row->zone3_count == $lastZones[2]
                    );

                    // 位置
                    $posAppear = [];
                    $lowPosNums = [];

                    for ($i=1;$i<=6;$i++) {
                        $num = $row->{'front_'.$i};
                        $count = $posCounts[$i][$num] ?? 0;

                        $posAppear[] = $count;

                        if ($count === 0) {
                            $lowPosNums[] = $num;
                        }
                    }

                    // 高频两码
                    $pairHit = false;
                    $pairScore = 0;
                    $hitPairs = [];

                    for ($i=0;$i<count($reds)-1;$i++) {
                        for ($j=$i+1;$j<count($reds);$j++) {
                            $key = $reds[$i].','.$reds[$j];

                            if (isset($hotPairs[$key])) {
                                $pairHit = true;
                                $pairScore += $hotPairs[$key];
                                $hitPairs[] = [
                                    'pair'=>$key,
                                    'count'=>$hotPairs[$key]
                                ];
                            }
                        }
                    }

                    return [
                        'id' => $row->id,
                        'front_numbers' => $row->front_numbers,
                        'back_numbers' => $row->back_numbers,

                        // ⭐⭐⭐ 关键补上 features
                        'features' => [
                            'span_same' => $row->span == $lastSpan,
                            'sum_same'  => $row->front_sum == $lastSum,
                            'zone_same' => $zoneSame,

                            'cold_numbers' => $thisCold,
                            'pos_appear'   => $posAppear,
                            'low_pos_nums' => $lowPosNums,

                            'pair_hit'   => $pairHit,
                            'pair_score' => $pairScore,
                            'hit_pairs'  => $hitPairs,
                        ]
                    ];
                });

                break;

            /**
             * =========================
             * 6️⃣ 排除跨度（不加权）
             * =========================
             */
            case 'history_span':
                $exclude = (array)$request->input('exclude',[]);
                $query = LottoSsqRecommendation::whereNull('ip');

                if (!empty($exclude)) {
                    $query->whereNotIn('span', $exclude);
                }

                $randomData = $query->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 7️⃣ 奇偶比（不加权）
             * =========================
             */
            case 'odd_even':

                if (empty($prefs['odd_even'])) {
                    return response()->json(['success'=>false,'message'=>'请选择奇偶比'],400);
                }

                [$odd,$even] = explode(':',$prefs['odd_even']);

                // -------------------------
                // 基础查询
                // -------------------------
                $results = LottoSsqRecommendation::whereNull('ip')
                    ->where('odd_count',(int)$odd)
                    ->where('even_count',(int)$even)
                    ->inRandomOrder()
                    ->take($take)
                    ->select([
                        'id','front_numbers','back_numbers','span','front_sum',
                        'zone1_count','zone2_count','zone3_count','consecutive_count',
                        'front_1','front_2','front_3','front_4','front_5','front_6'
                    ])
                    ->get();

                if ($results->isEmpty()) {
                    return response()->json([
                        'success'=>false,
                        'message'=>'没有符合条件的号码'
                    ]);
                }

                // -------------------------
                // 上期特征（统一）
                // -------------------------
                $last = Cache::remember('ssq_last_issue_features', 60, function () {

                    $last = DB::table('ssq_lotto_history')
                        ->orderByDesc('id')
                        ->first();

                    if (!$last) return null;

                    return [
                        'span' => $last->span,
                        'front_sum' => $last->front_sum,
                        'zone_ratio' => explode(',', $last->zone_ratio),
                        'cold_numbers' => json_decode($last->next_red_max_miss_json, true) ?? []
                    ];
                });

                if (!$last) {
                    return response()->json([
                        'success' => false,
                        'message' => '暂无历史数据'
                    ]);
                }

                $coldNumbers = $last['cold_numbers'];
                $lastZones   = $last['zone_ratio'];
                $lastSpan    = $last['span'];
                $lastSum     = $last['front_sum'];

                // -------------------------
                // 高频两码
                // -------------------------
                $hotPairs = $this->getHotPairs(6);

                // -------------------------
                // 位置统计
                // -------------------------
                $posCounts = Cache::remember('ssq_last80_pos_counts', 60, function () {

                    $rows = DB::table('ssq_lotto_history')
                        ->orderByDesc('id')
                        ->limit(80)
                        ->select(['front1','front2','front3','front4','front5','front6'])
                        ->get();

                    $counts = [];
                    for ($i = 1; $i <= 6; $i++) {
                        $counts[$i] = [];
                    }

                    foreach ($rows as $row) {
                        for ($i = 1; $i <= 6; $i++) {
                            $num = $row->{'front'.$i};
                            $counts[$i][$num] = ($counts[$i][$num] ?? 0) + 1;
                        }
                    }

                    return $counts;
                });

                // -------------------------
                // 构建统一结构
                // -------------------------
                $randomData = $results->map(function($row) use (
                    $lastSpan,$lastSum,$lastZones,
                    $coldNumbers,$posCounts,$hotPairs
                ) {

                    $reds = array_map('intval', explode(',', $row->front_numbers));

                    // ❄️ 冷号
                    $thisCold = array_values(array_intersect($reds, $coldNumbers));

                    // 📊 区间比是否一致
                    $zoneSame = (
                        $row->zone1_count == $lastZones[0] &&
                        $row->zone2_count == $lastZones[1] &&
                        $row->zone3_count == $lastZones[2]
                    );

                    // 📍 位置统计 + 黑号
                    $posAppear = [];
                    $lowPosNums = [];

                    for ($i = 1; $i <= 6; $i++) {
                        $num = $row->{'front_'.$i};
                        $count = $posCounts[$i][$num] ?? 0;

                        $posAppear[] = $count;

                        if ($count === 0) {
                            $lowPosNums[] = $num;
                        }
                    }

                    // 🔥 高频两码
                    $pairHit = false;
                    $pairScore = 0;
                    $hitPairs = [];

                    for ($i = 0; $i < count($reds) - 1; $i++) {
                        for ($j = $i + 1; $j < count($reds); $j++) {

                            $key = $reds[$i] . ',' . $reds[$j];

                            if (isset($hotPairs[$key])) {
                                $pairHit = true;
                                $pairScore += $hotPairs[$key];
                                $hitPairs[] = [
                                    'pair'  => $key,
                                    'count' => $hotPairs[$key]
                                ];
                            }
                        }
                    }

                    return [
                        'id' => $row->id,
                        'front_numbers' => $row->front_numbers,
                        'back_numbers'  => $row->back_numbers,

                        'features' => [
                            'span_same'   => $row->span == $lastSpan,
                            'sum_same'    => $row->front_sum == $lastSum,
                            'zone_same'   => $zoneSame,

                            // ⭐统一字段（关键）
                            'cold_numbers' => $thisCold,
                            'pos_appear'   => $posAppear,
                            'low_pos_nums' => $lowPosNums,

                            'pair_hit'     => $pairHit,
                            'pair_score'   => $pairScore,
                            'hit_pairs'    => $hitPairs,
                        ]
                    ];
                });

            break;

            /**
             * =========================
             * 8️⃣ 首尾号（不加权 + 高频 + 冷号统一结构）
             * =========================
             */
            case 'first_last':

                $query = LottoSsqRecommendation::whereNull('ip');

                if (!empty($prefs['first'])) {
                    $query->where('front_1', $prefs['first']);
                }

                if (!empty($prefs['last'])) {
                    $query->where('front_6', $prefs['last']);
                }

                $results = $query->inRandomOrder()
                    ->take($take)
                    ->select([
                        'id','front_numbers','back_numbers','span','front_sum',
                        'zone1_count','zone2_count','zone3_count','consecutive_count',
                        'front_1','front_2','front_3','front_4','front_5','front_6'
                    ])
                    ->get();

                if ($results->isEmpty()) {
                    return response()->json([
                        'success' => false,
                        'message' => '没有符合条件的号码'
                    ]);
                }

                // =========================
                // 上期特征（统一缓存）
                // =========================
                $lastIssue = Cache::remember('ssq_last_issue_features', 60, function () {

                    $last = DB::table('ssq_lotto_history')
                        ->orderByDesc('id')
                        ->first();

                    if (!$last) return null;

                    return [
                        'span' => $last->span,
                        'front_sum' => $last->front_sum,
                        'zone_ratio' => explode(',', $last->zone_ratio),
                        'cold_numbers' => json_decode($last->next_red_max_miss_json, true) ?? []
                    ];
                });

                if (!$lastIssue) {
                    return response()->json([
                        'success' => false,
                        'message' => '暂无历史数据'
                    ]);
                }

                $coldNumbers = $lastIssue['cold_numbers'];
                $lastZones   = $lastIssue['zone_ratio'];
                $lastSpan    = $lastIssue['span'];
                $lastSum     = $lastIssue['front_sum'];

                // =========================
                // 高频两码（统一）
                // =========================
                $hotPairs = $this->getHotPairs(6);

                // =========================
                // 近80期位置统计
                // =========================
                $posCounts = Cache::remember('ssq_last80_pos_counts', 60, function () {

                    $rows = DB::table('ssq_lotto_history')
                        ->orderByDesc('id')
                        ->limit(80)
                        ->select(['front1','front2','front3','front4','front5','front6'])
                        ->get();

                    $counts = [];
                    for ($i = 1; $i <= 6; $i++) {
                        $counts[$i] = [];
                    }

                    foreach ($rows as $row) {
                        for ($i = 1; $i <= 6; $i++) {
                            $num = $row->{'front'.$i};
                            $counts[$i][$num] = ($counts[$i][$num] ?? 0) + 1;
                        }
                    }

                    return $counts;
                });

                // =========================
                // 构建统一返回结构
                // =========================
                $randomData = $results->map(function ($row) use (
                    $lastSpan, $lastSum, $lastZones,
                    $coldNumbers, $posCounts, $hotPairs
                ) {

                    $reds = array_map('intval', explode(',', $row->front_numbers));
                    sort($reds);

                    // ❄ 冷号
                    $thisCold = array_values(array_intersect($reds, $coldNumbers));

                    // 📊 区间比
                    $zoneSame = (
                        $row->zone1_count == $lastZones[0] &&
                        $row->zone2_count == $lastZones[1] &&
                        $row->zone3_count == $lastZones[2]
                    );

                    // 📍 位置统计
                    $posAppear = [];
                    $lowPosNums = [];

                    for ($i = 1; $i <= 6; $i++) {
                        $num = $row->{'front_'.$i};
                        $count = $posCounts[$i][$num] ?? 0;

                        $posAppear[] = $count;

                        if ($count === 0) {
                            $lowPosNums[] = $num;
                        }
                    }

                    // 🔥 高频两码
                    $pairHit = false;
                    $pairScore = 0;
                    $hitPairs = [];

                    $len = count($reds);

                    for ($i = 0; $i < $len - 1; $i++) {
                        for ($j = $i + 1; $j < $len; $j++) {

                            $key = $reds[$i] . ',' . $reds[$j];

                            if (isset($hotPairs[$key])) {
                                $pairHit = true;
                                $pairScore += $hotPairs[$key];
                                $hitPairs[] = [
                                    'pair'  => $key,
                                    'count' => $hotPairs[$key]
                                ];
                            }
                        }
                    }

                    return [
                        'id' => $row->id,
                        'front_numbers' => $row->front_numbers,
                        'back_numbers'  => $row->back_numbers,

                        // ⭐统一 features（重点）
                        'features' => [
                            'span_same'    => $row->span == $lastSpan,
                            'sum_same'     => $row->front_sum == $lastSum,
                            'zone_same'    => $zoneSame,

                            'cold_numbers' => $thisCold,

                            'continue_count' => $row->consecutive_count,

                            'pos_appear'   => $posAppear,
                            'low_pos_nums' => $lowPosNums,

                            'pair_hit'     => $pairHit,
                            'pair_score'   => $pairScore,
                            'hit_pairs'    => $hitPairs,
                        ]
                    ];
                });

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
        LottoSsqRecommendation::whereIn(
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

        $list = LottoSsqRecommendation::where('ip',$ip)
            ->orderBy('id')
            ->get();

        if ($list->isEmpty()) {
            return response()->json(['success'=>false,'message'=>'暂无可下载数据'],404);
        }

        $content = '';
        foreach ($list as $i=>$row) {
            $content .= sprintf(
                "%02d. 红球:%s | 蓝球:%s\n",
                $i+1,
                $row->front_numbers,
                $row->back_numbers
            );
        }

        return response($content,200,[
            'Content-Type'=>'text/plain; charset=UTF-8',
            'Content-Disposition'=>'attachment; filename="ssq.txt"'
        ]);
    }


    /**
     * 双色球号码分布统计接口
     */
    public function numberDistribution(Request $request)
    {
        $periods = (int) $request->query('periods', 50); // 默认近50期

        // 获取最近 $periods 期号
        $issues = DB::table('ssq_lotto_history')
            ->orderByDesc('issue')
            ->limit($periods)
            ->pluck('issue')
            ->toArray();

        if (empty($issues)) {
            return response()->json(['code' => 200, 'data' => []]);
        }

        $positions = ['front1', 'front2', 'front3', 'front4', 'front5', 'front6']; // 前五红球+蓝球/第六位
        $result = [];

        foreach ($positions as $pos) {
            // 获取该位置每个数字出现次数
            $counts = DB::table('ssq_lotto_history')
                ->select($pos . ' as number', DB::raw('COUNT(*) as count'))
                ->whereIn('issue', $issues)
                ->groupBy($pos)
                ->orderBy($pos)
                ->get()
                ->toArray();

            // 转成数组
            $result[] = array_map(function($item) {
                return ['number' => $item->number, 'count' => $item->count];
            }, $counts);
        }

        return response()->json([
            'code' => 200,
            'data' => $result
        ]);
    }


   public function lastIssue(Request $request)
    {
        $last = DB::table('ssq_lotto_history')
            ->orderByDesc('id')
            ->first();

        if (!$last) {
            return response()->json([
                'success' => false,
                'message' => '暂无历史开奖数据'
            ]);
        }

        // -------------------------
        // 灰色：最大遗漏号码
        // -------------------------
        $maxMissNums = json_decode($last->red_max_miss_json, true) ?? [];

        // -------------------------
        // 黑色：近80期该位置未出现号码
        // 存的是：{1:[xx],2:[],3:[xx]}
        // 👉 转成纯号码数组
        // -------------------------
        $posMissRaw = json_decode($last->red_position_80_miss_json, true) ?? [];

        $posMissNums = [];
        foreach ($posMissRaw as $nums) {
            if (!empty($nums)) {
                $posMissNums = array_merge($posMissNums, $nums);
            }
        }

        // 去重（保险）
        $posMissNums = array_values(array_unique($posMissNums));

        // -------------------------
        // 返回
        // -------------------------
        $data = [
            'front_numbers' => $last->front_numbers,
            'back_numbers'  => $last->back_numbers,
            'features' => [
                'cold_numbers'   => $maxMissNums,
                'pos_miss_nums'  => $posMissNums,
                'continue_count' => $last->continue_count ?? 0
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function pairStats()
    {
        return response()->json([
            'data' => $this->getPairStatsData()
        ]);
    }
   
    private function getPairStatsData()
    {
        return Cache::remember('ssq_pair_stats_100', 3600, function () {

            $rows = DB::table('ssq_lotto_history')
                ->orderByDesc('issue')
                ->limit(100)
                ->get();

            $counts = [];

            foreach ($rows as $row) {

                $numbers = [
                    $row->front1,
                    $row->front2,
                    $row->front3,
                    $row->front4,
                    $row->front5,
                    $row->front6
                ];

                sort($numbers);

                $len = count($numbers);

                for ($i = 0; $i < $len - 1; $i++) {
                    for ($j = $i + 1; $j < $len; $j++) {

                        // 防止 33,33 这种异常
                        if ($numbers[$i] == $numbers[$j]) continue;

                        $key = $numbers[$i] . ',' . $numbers[$j];

                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                }
            }

            return $counts; // ✅ 只返回数组
        });
    }


    /**
     * 获取高频两码组合（>= 指定次数）
     * 默认：最近100期，出现 >5 次
     */
    private function getHotPairs($minCount = 6)
    {
        return Cache::remember("ssq_hot_pairs_100_{$minCount}", 3600, function () use ($minCount) {

            // 拿基础统计（你之前那个方法）
            $pairStats = $this->getPairStatsData();

            $hotPairs = [];

            foreach ($pairStats as $key => $count) {
                if ($count >= $minCount) {
                    $hotPairs[$key] = $count; // 保留次数，后面可以做权重
                }
            }

            return $hotPairs;
        });
    }
}
