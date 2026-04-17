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

        // 限制次数
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

        // ⭐统一依赖（所有玩法共享）
        $last = $this->getLastIssueFeatures();
        $posCounts = $this->getPosCounts();
        $hotPairs = $this->getHotPairs(6);

        if (!$last && !in_array($type, ['connect','history_span'])) {
            return response()->json([
                'success' => false,
                'message' => '暂无历史开奖数据'
            ]);
        }

        $results = collect();

        switch ($type) {

            case 'normal':

                $results = LottoSsqRecommendation::whereNull('ip')
                    ->whereIn('weight', [0,1,2,3,4,5])
                    ->inRandomOrder()
                    ->take($take)
                    ->get();

            break;

            case 'dan_only':

                $dan = (array)($prefs['dan'] ?? []);

                if (count($dan) < 1 || count($dan) > 5) {
                    return response()->json([
                        'success' => false,
                        'message' => '胆码数量必须 1–5 个'
                    ], 400);
                }

                $query = LottoSsqRecommendation::whereNull('ip');

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

                $results = $query->inRandomOrder()->take($take)->get();

            break;

            case 'first_advantage':

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

                // ⭐ 用于返回给前端
                $firstAdvTop = array_slice($firstCounts, 0, $topLimit, true);

                // ⭐ 用于查询
                $strongFirst = array_keys($firstAdvTop);

                $results = LottoSsqRecommendation::whereNull('ip')
                    ->whereIn('front_1', $strongFirst)
                    ->inRandomOrder()
                    ->take($take)
                    ->get();

            break;

            case 'connect':

                $consecutive = (int)($prefs['serial'] ?? 0);

                if ($consecutive <= 0) {
                    return response()->json(['success'=>false,'message'=>'请选择连号个数'],400);
                }

                $results = LottoSsqRecommendation::whereNull('ip')
                    ->where('consecutive_count', $consecutive)
                    ->inRandomOrder()
                    ->take($take)
                    ->get();

            break;

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

                $query = LottoSsqRecommendation::whereNull('ip');

                if (!empty($excludeSums)) {
                    $query->whereNotIn('front_sum', $excludeSums);
                }

                $results = $query->inRandomOrder()->take($take)->get();

            break;

            case 'history_span':

                $exclude = (array)$request->input('exclude',[]);

                $query = LottoSsqRecommendation::whereNull('ip');

                if (!empty($exclude)) {
                    $query->whereNotIn('span', $exclude);
                }

                $results = $query->inRandomOrder()->take($take)->get();

            break;

            case 'odd_even':

                if (empty($prefs['odd_even'])) {
                    return response()->json(['success'=>false,'message'=>'请选择奇偶比'],400);
                }

                [$odd,$even] = explode(':',$prefs['odd_even']);

                $results = LottoSsqRecommendation::whereNull('ip')
                    ->where('odd_count',(int)$odd)
                    ->where('even_count',(int)$even)
                    ->inRandomOrder()
                    ->take($take)
                    ->get();

            break;

            case 'first_last':

                $query = LottoSsqRecommendation::whereNull('ip');

                if (!empty($prefs['first'])) {
                    $query->where('front_1', $prefs['first']);
                }

                if (!empty($prefs['last'])) {
                    $query->where('front_6', $prefs['last']);
                }

                $results = $query->inRandomOrder()->take($take)->get();

            break;

            default:
                return response()->json([
                    'success'=>false,
                    'message'=>'未知机选类型'
                ],400);
        }

        if ($results->isEmpty()) {
            return response()->json([
                'success'=>false,
                'message'=>'没有符合条件的号码'
            ]);
        }

        // ⭐统一构建 features（核心优化点）
        $randomData = $results->map(function ($row) use ($last, $posCounts, $hotPairs, $type) {

            // connect / history_span 不需要特征
            if (in_array($type, ['connect','history_span'])) {
                return [
                    'id' => $row->id,
                    'front_numbers' => $row->front_numbers,
                    'back_numbers'  => $row->back_numbers
                ];
            }

            return [
                'id' => $row->id,
                'front_numbers' => $row->front_numbers,
                'back_numbers'  => $row->back_numbers,
                'features' => $this->buildFeatures($row, $last, $posCounts, $hotPairs)
            ];
        });

        // 绑定 IP
        LottoSsqRecommendation::whereIn(
            'id',
            $randomData->pluck('id')->toArray()
        )->update(['ip'=>$ip, 'mode'=>$type]);

        $response = [
            'success' => true,
            'data' => $randomData,
            'remain' => $remaining - $randomData->count(),
        ];

        // ⭐ 只在首红玩法返回
        if ($type === 'first_advantage') {
            $response['first_advantage_top'] = $firstAdvTop ?? [];
        }

        return response()->json($response);
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

    private function getLastIssueFeatures()
    {
        return Cache::remember('ssq_last_issue_features', 60, function () {

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
    }

    private function getPosCounts()
    {
        return Cache::remember('ssq_last80_pos_counts', 60, function () {

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
    }

    private function buildFeatures($row, $last, $posCounts, $hotPairs)
    {
        $reds = array_map('intval', explode(',', $row->front_numbers));
        sort($reds);

        $cold = array_values(array_intersect($reds, $last['cold_numbers']));

        $zoneSame = (
            $row->zone1_count == $last['zone_ratio'][0] &&
            $row->zone2_count == $last['zone_ratio'][1] &&
            $row->zone3_count == $last['zone_ratio'][2]
        );

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
                        'pair' => $key,
                        'count' => $hotPairs[$key]
                    ];
                }
            }
        }

        return [
            'span_same' => $row->span == $last['span'],
            'sum_same'  => $row->front_sum == $last['front_sum'],
            'zone_same' => $zoneSame,

            'cold_numbers' => $cold,
            'pos_appear'   => $posAppear,
            'low_pos_nums' => $lowPosNums,

            'pair_hit'   => $pairHit,
            'pair_score' => $pairScore,
            'hit_pairs'  => $hitPairs,

            'continue_count' => $row->consecutive_count
        ];
    }
}
