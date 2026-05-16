<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\BasicDlt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Services\DltLotteryFeatureService;
use App\Models\LotterySetting;

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
            ->whereDate('created_at', now()->toDateString())
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
        $query = BasicDlt::whereNull('user_id');

        // 3. 玩法逻辑分支
        switch ($type) {
            case 'normal':
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            case 'first_advantage':
                $firstAdvTop = Cache::remember('dlt_first_adv_top5', 60, function () {
                    // 确保字段名为 front1
                    $issues = DB::table('dlt_lotto_history')->orderByDesc('id')->limit(80)->pluck('front1');
                    $map = [];
                    foreach ($issues as $num) { $map[(int)$num] = ($map[(int)$num] ?? 0) + 1; }
                    arsort($map);
                    return array_slice($map, 0, 5, true);
                });
                // 筛选前区第一位红球属于 Top5 的记录
                $results = $query->whereIn('code1', array_keys($firstAdvTop))->inRandomOrder()->take($take)->get();
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
                        $q->orWhere('code1', $num)->orWhere('code2', $num)->orWhere('code3', $num)->orWhere('code4', $num)->orWhere('code5', $num);
                    });
                }
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            // --- ⭐ 新增：包含近期和值逻辑 ---
            case 'include_sum':
                // 获取前端传来的范围期数，默认 10 期
                $includeCount = (int)$request->input('exclude', 10); 
                // 获取最近 N 期的和值集合
                $includeSums = DB::table('dlt_lotto_history')
                    ->orderByDesc('issue')
                    ->limit($includeCount)
                    ->pluck('sum')
                    ->unique() // 去重，提高查询效率
                    ->toArray();

                if (!empty($includeSums)) {
                    $query->whereIn('sum', $includeSums);
                } else {
                    return response()->json(['success' => false, 'message' => '无法获取历史和值数据'], 400);
                }
                $results = $query->inRandomOrder()->take($take)->get();
                break;
                    
            case 'history_sum':
                $excludeCount = (int)$request->input('exclude', 0);
                $excludeSums = $excludeCount > 0 ? DB::table('dlt_lotto_history')->orderByDesc('issue')->limit($excludeCount)->pluck('sum')->toArray() : [];
                if (!empty($excludeSums)) $query->whereNotIn('sum', $excludeSums);
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            case 'odd_even':
                if (empty($prefs['odd_even'])) return response()->json(['success' => false, 'message' => '请选择奇偶比'], 400);
                [$odd, $even] = explode(':', $prefs['odd_even']);
                $results = $query->where('odd_count', (int)$odd)->where('even_count', (int)$even)->inRandomOrder()->take($take)->get();
                break;

            case 'first_last':
                if (!empty($prefs['first'])) $query->where('code1', $prefs['first']);
                if (!empty($prefs['last'])) $query->where('code5', $prefs['last']);
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

        //或取当前期号
        $issue = $this->currentIssue();

        // 5. ⭐ 自动保存到永久记录表 (包含期号修复逻辑)
        $records = [];
        foreach ($results as $row) {
            $records[] = [
                'user_id'       => $user->id,
                'lottery_type'  => 'dlt',
                'is_fushi'      => 0, // 机选默认为单式
                'issue'         => $issue,
                'mode'          => $type,
                'red_numbers'   => $row->front, // 前区
                'blue_numbers'  => $row->back,  // 后区
                'red_dan'       => '',
                'kill_numbers'  => '',
                'ip'            => $ip,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }
        DB::table('user_lotto_records')->insert($records);

        // 标记推荐池记录
        BasicDlt::whereIn('id', $results->pluck('id'))->update([
            'user_id' => $user->id
        ]);

        return response()->json([
            'success' => true,
            'data'    => $randomData,
            'remain'  => $remaining - $results->count(),
            // 修复点：添加这个判断，返回前端渲染 Top5 用的数据
            'first_advantage_top' => $firstAdvTop ?? null 
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
     * 获取当前期号（缓存5分钟）
     */
    private function currentIssue()
    {
        return \Illuminate\Support\Facades\Cache::remember('dlt_current_issue_for_pick', 300, function () {
            // 获取最新一期
            $issue = LotterySetting::where('type', 2)
                ->orderByDesc('issue')
                ->first();

            return $issue ? $issue->issue : null;
        });
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
                'front_numbers' => $last->front,
                'back_numbers'  => $last->back,
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


    
    /**
     * 大乐透深度演算评分报告
     * 整合：高频重号压制、断区趋势预警、和尾连开拦截、和值区间停留拦截、形态拦截、前三位奇偶形态拦截
     */
    public function score(Request $request)
    {
        $id = $request->input('id');
        if ($id) {
            $row = DB::table('basic_dlt')->where('id', $id)->first();
        } else {
            $frontNumbers = $request->input('front_numbers');
            $row = DB::table('basic_dlt')->where('front', $frontNumbers)->first();
        }

        // 2. 获取历史数据 (最近 10 期)
        $recentHistory = DB::table('dlt_lotto_history')->orderBy('id', 'desc')->limit(10)->get();
        if ($recentHistory->isEmpty()) return response()->json(['success' => false, 'message' => '历史数据为空']);
        
        $latestHistory = $recentHistory->get(0); // 上期
        $preHistory = $recentHistory->get(1);    // 上上期

        // --- 基础数据准备 ---
        $currentFronts = [(int)$row->code1, (int)$row->code2, (int)$row->code3, (int)$row->code4, (int)$row->code5];
        sort($currentFronts);

        $lastFronts = [(int)$latestHistory->front1, (int)$latestHistory->front2, (int)$latestHistory->front3, (int)$latestHistory->front4, (int)$latestHistory->front5];
        sort($lastFronts);

        $reasons = [];
        $baseScore = 95;

        // 定义区间映射逻辑
        $getPositionSegment = function($pos) {
            if ($pos <= 99999) return 1;
            if ($pos <= 199999) return 2;
            if ($pos <= 299999) return 3;
            return 4; // 300000 - 330000+
        };

        if ($preHistory) {
            $currentSegment = $getPositionSegment($row->id); // 当前评分号所在的 basic_dlt id
            $lastSegment = $getPositionSegment($latestHistory->position); // 上期历史
            $preSegment = $getPositionSegment($preHistory->position);   // 上上期历史

            // 如果最近两期在同一个区间，且当前号也在同一个区间
            if ($lastSegment === $preSegment && $currentSegment === $lastSegment) {
                $baseScore -= 80;
                $reasons[] = "号码连续落在一个区间（区间{$currentSegment}），评分下降。";
            }
        }

        // --- 核心逻辑 A：重号拦截与极限压制 ---
        $currentDuplicateWithLast = array_intersect($currentFronts, $lastFronts);
        $currentDupCount = count($currentDuplicateWithLast);
        $lastPeriodDupCount = (int)$latestHistory->duplicate_count; 

        if ($lastPeriodDupCount >= 3 && $currentDupCount >= 3) {
            $baseScore -= 90;
            $numsStr = implode(',', $currentDuplicateWithLast);
            $reasons[] = "形态高频过载：上期已出现 {$lastPeriodDupCount} 个重号，本组合再次出现 {$currentDupCount} 个重号({$numsStr})。";
        } elseif ($currentDupCount >= 3) {
            $baseScore -= 40;
            $reasons[] = "重号偏多：与上期重复 {$currentDupCount} 个号码。";
        }

        // --- 核心逻辑 B：和值区间停留拦截 ---
        $currentSumRange = floor((int)$row->sum / 10);
        $lastSumRange = floor((int)$latestHistory->sum / 10);
        $lastSumRangeCount = (int)$latestHistory->continuous_sum_range; 

        if ($currentSumRange == $lastSumRange && $lastSumRangeCount >= 2) {
            $baseScore -= 30;
            $rangeStart = $currentSumRange * 10;
            $rangeEnd = $rangeStart + 9;
            $reasons[] = "和值区间过热：和值已连续 2 期停留在 {$rangeStart}-{$rangeEnd} 范围内，本组合再次落入该区间，开出概率极低。";
        }

        // --- 核心逻辑 C：区间比与断区预警逻辑 ---
        $last3History = $recentHistory->take(3);
        $historyHasBrokenZone = false;
        foreach ($last3History as $h) {
            $ratios = explode(':', $h->zone_ratio);
            if (in_array('0', $ratios)) { $historyHasBrokenZone = true; break; }
        }
        $currentIsBroken = ($row->zone1_count == 0 || $row->zone2_count == 0 || $row->zone3_count == 0);

        if (!$historyHasBrokenZone && !$currentIsBroken) {
            $baseScore -= 10;
            $reasons[] = "历史趋近断区号：最近3期均未出现断区，当前组合亦无断区，警惕反弹。";
        }

        // --- 核心逻辑 D：和值个位（和尾）连开拦截 ---
        $currentSumTail = (int)$row->sum % 10;
        $lastSumTail = (int)$latestHistory->sum % 10;
        $lastSumTailCount = (int)$latestHistory->continuous_sum_tail;

        if ($currentSumTail === $lastSumTail && $lastSumTailCount >= 2) {
            $baseScore -= 50;
            $reasons[] = "和值个位（{$currentSumTail}）已连开 2 期，执行拦截。";
        }

        // --- 【新增】核心逻辑 G：前三位奇偶形态拦截 ---
        $getCurrentParityStr = function($c1, $c2, $c3) {
            return ($c1 % 2 === 0 ? '偶' : '奇') . ($c2 % 2 === 0 ? '偶' : '奇') . ($c3 % 2 === 0 ? '偶' : '奇');
        };

        $currentP = $getCurrentParityStr($currentFronts[0], $currentFronts[1], $currentFronts[2]);
        $lastP = $getCurrentParityStr($latestHistory->front1, $latestHistory->front2, $latestHistory->front3);

        if ($currentP === $lastP) {
            // 判断是否三连
            if ($preHistory) {
                $preP = $getCurrentParityStr($preHistory->front1, $preHistory->front2, $preHistory->front3);
                if ($currentP === $preP) {
                    $baseScore -= 90;
                    $reasons[] = "前三位奇偶形态({$currentP})达成三连，风险极大。";
                } else {
                    $baseScore -= 10;
                    $reasons[] = "前三位奇偶形态({$currentP})与上期雷同，降低权重。";
                }
            } else {
                $baseScore -= 20;
                $reasons[] = "前三位奇偶形态({$currentP})与上期雷同。";
            }
        }

        // --- 核心逻辑 E：连号复刻拦截 ---
        $lastConsecutiveSets = [];
        $tempSet = [$lastFronts[0]];
        for ($i = 1; $i < count($lastFronts); $i++) {
            if ($lastFronts[$i] == $lastFronts[$i - 1] + 1) {
                $tempSet[] = $lastFronts[$i];
            } else {
                if (count($tempSet) >= 2) $lastConsecutiveSets[] = $tempSet;
                $tempSet = [$lastFronts[$i]];
            }
        }
        if (count($tempSet) >= 2) $lastConsecutiveSets[] = $tempSet;

        foreach ($lastConsecutiveSets as $set) {
            if (count(array_intersect($currentFronts, $set)) === count($set)) {
                $baseScore -= 60;
                $setStr = implode('-', $set);
                $reasons[] = "连号复刻警告：包含了与上期相同的连号组({$setStr})。";
                break; 
            }
        }

        // --- 核心逻辑 F：热号及其他形态 ---
        $allRecentFronts = [];
        foreach ($recentHistory as $h) {
            $allRecentFronts = array_merge($allRecentFronts, [(int)$h->front1, (int)$h->front2, (int)$h->front3, (int)$h->front4, (int)$h->front5]);
        }
        $counts = array_count_values($allRecentFronts);
        $hotNumbers = array_keys(array_filter($counts, fn($v) => $v >= 2));
      
        $hotIntersect = array_intersect($currentFronts, $hotNumbers);

        if (count($hotIntersect) === 0) {
            $baseScore -= 60;
            $reasons[] = "未包含近 10 期内的高频热号。";
        }

        if ($row->odd_count == 5 || $row->odd_count == 0) {
            return response()->json(['success' => true, 'data' => ['weight' => 50, 'reason' => "极端奇偶形态，建议防御。"]]);
        }

        // --- 结果合成 ---
        if (empty($reasons)) {
            $reasons[] = "号码各项指标分布均衡，符合大乐透常规历史走势。";
        }

        return response()->json([
            'success' => true,
            'data' => [
                'weight' => max(0, (int)$baseScore),
                'reason' => implode(' ', $reasons)
            ]
        ]);
    }

    /**
     * 获取双色球历史统计特征（如和值间隔）
     */
    public function sum_interval(Request $request)
    {
        // 1. 接收参数：默认取 15 条
        $limit = $request->query('limit', 15);
        $order = $request->query('order', 'asc'); // 前端传 asc 代表顺序

        try {
            // 2. 从数据库获取最近的 N 期数据
            // 注意：为了拿到“最近”的15期，我们先按 issue 倒序取，再根据需求决定是否翻转
            $query = DB::table('dlt_lotto_history')
                ->select(['issue', 'sum', 'sum_interval'])
                ->orderBy('issue', 'desc')
                ->limit($limit);

            $data = $query->get();

            // 3. 处理顺序逻辑
            // 数据库取出来的是 [最新期, 上期, 上上期...]
            // 如果前端要求顺序 [旧期 -> 新期]，我们需要 reverse
            if ($order === 'asc') {
                $data = $data->reverse()->values(); 
            }

            return response()->json([
                'success' => true,
                'data'    => $data,
                'message' => '获取成功'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '服务器错误：' . $e->getMessage()
            ], 500);
        }
    }

        /**
     * 获取大乐透历史首尾号段趋势 (前区龙头/凤尾)
     * 返回顺序：按期号由小到大 (由旧到新)
     */
    public function edgeHistory(Request $request)
    {
        $limit = $request->query('limit', 20);

        try {
            $data = DB::table('dlt_lotto_history') // 注意表名
                ->select(['issue', 'front1', 'front5', 'span']) // 大乐透末位是 front5
                ->orderBy('issue', 'desc')
                ->limit($limit)
                ->get()
                ->reverse() 
                ->values()   
                ->map(function ($item) {
                    return [
                        'issue' => $item->issue,
                        'issue_short' => substr($item->issue, -3) . '期',
                        'first' => str_pad($item->front1, 2, '0', STR_PAD_LEFT),
                        'last' => str_pad($item->front5, 2, '0', STR_PAD_LEFT), // 大乐透对应字段
                        'span' => $item->span
                    ];
                });

            return response()->json([
                'success' => true,
                'data'    => $data,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '获取首尾历史失败: ' . $e->getMessage()
            ], 500);
        }
    }
}