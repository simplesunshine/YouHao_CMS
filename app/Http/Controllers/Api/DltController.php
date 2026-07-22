<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\BasicDlt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Services\DltLotteryFeatureService;
use App\Models\LotterySetting;
use App\Models\DltLottoHistory;

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
            
        $maxPerUser = 10000;
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
        $query = BasicDlt::whereNull('user_id');

        // 3. 玩法逻辑分支
        switch ($type) {
            case 'normal':
                // 直接进入高性能随机池
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;

            case 'first_advantage':
                $firstAdvTop = Cache::remember('dlt_first_adv_top5', 60, function () {
                    $issues = DB::table('dlt_lotto_history')->orderByDesc('id')->limit(80)->pluck('front1');
                    $map = [];
                    foreach ($issues as $num) { $map[(int)$num] = ($map[(int)$num] ?? 0) + 1; }
                    arsort($map);
                    return array_slice($map, 0, 5, true);
                });
                $query->whereIn('code1', array_keys($firstAdvTop));
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;

            case 'connect':
                $consecutive = (int)($prefs['serial'] ?? 0);
                if ($consecutive <= 0) return response()->json(['success' => false, 'message' => '请选择连号个数'], 400);
                $query->where('consecutive_count', $consecutive);
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;

            case 'dan_only':
                $frontDan = (array)($prefs['front_dan'] ?? []);
                if (empty($frontDan) || count($frontDan) > 4) return response()->json(['success' => false, 'message' => '前区胆码数量 1-4 个'], 400);
                foreach ($frontDan as $num) {
                    $query->where(function ($q) use ($num) {
                        $q->orWhere('code1', $num)->orWhere('code2', $num)->orWhere('code3', $num)->orWhere('code4', $num)->orWhere('code5', $num);
                    });
                }
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;

            case 'include_sum':
                $includeCount = (int)$request->input('exclude', 10); 
                $includeSums = DB::table('dlt_lotto_history')
                    ->orderByDesc('issue')
                    ->limit($includeCount)
                    ->pluck('sum')
                    ->unique() 
                    ->toArray();

                if (!empty($includeSums)) {
                    $query->whereIn('sum', $includeSums);
                } else {
                    return response()->json(['success' => false, 'message' => '无法获取历史和值数据'], 400);
                }
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;
                    
            case 'history_sum':
                $excludeCount = (int)$request->input('exclude', 0);
                $excludeSums = $excludeCount > 0 ? DB::table('dlt_lotto_history')->orderByDesc('issue')->limit($excludeCount)->pluck('sum')->toArray() : [];
                if (!empty($excludeSums)) $query->whereNotIn('sum', $excludeSums);
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;

            case 'odd_even':
                if (empty($prefs['odd_even'])) return response()->json(['success' => false, 'message' => '请选择奇偶比'], 400);
                [$odd, $even] = explode(':', $prefs['odd_even']);
                $query->where('odd_count', (int)$odd)->where('even_count', (int)$even);
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;

            case 'first_last':
                if (!empty($prefs['first'])) $query->where('code1', $prefs['first']);
                if (!empty($prefs['last'])) $query->where('code5', $prefs['last']);
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;
            // ==================== 【新增】大乐透精细化定位置过滤逻辑 ====================
            case 'kill_pick':
                $killFrontStr = $prefs['kill_front'] ?? '';
                if (!empty($killFrontStr)) {
                    $killFrontArray = array_filter(explode(',', $killFrontStr), function($val) {
                        return $val !== '' && is_numeric($val);
                    });
                    $killFrontArray = array_map('intval', $killFrontArray);

                    if (count($killFrontArray) > 15) {
                        return response()->json(['success' => false, 'message' => '前区杀号上限不可超过 15 码'], 400);
                    }

                    if (!empty($killFrontArray)) {
                        $query->whereNotIn('code1', $killFrontArray)
                              ->whereNotIn('code2', $killFrontArray)
                              ->whereNotIn('code3', $killFrontArray)
                              ->whereNotIn('code4', $killFrontArray)
                              ->whereNotIn('code5', $killFrontArray);
                    }
                }
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;
                case 'advanced_filter':
                    // ==================== 大乐透精细化定位置过滤逻辑 ====================
                    
                    // 1. P1 ~ P5 定位置选号池过滤
                    for ($i = 1; $i <= 5; $i++) {
                        $posKey = "p{$i}";
                        if ($request->has($posKey) && is_array($request->input($posKey)) && !empty($request->input($posKey))) {
                            // 字段对应 basic_dlt 表中的 code1 ~ code5
                            $query->whereIn("code{$i}", array_map('intval', $request->input($posKey)));
                        }
                    }

                    // 2. 奇偶形态断言过滤 (P1 ~ P5 分别控限制)
                    if ($request->has('parityMode') && is_array($request->input('parityMode'))) {
                        $parityMode = $request->input('parityMode');
                        for ($i = 1; $i <= 5; $i++) {
                            $posKey = "p{$i}";
                            if (isset($parityMode[$posKey]) && $parityMode[$posKey] !== 'ignore') {
                                if ($parityMode[$posKey] === 'even') {
                                    // 必须为偶数：对 2 取模等于 0
                                    $query->whereRaw("code{$i} % 2 = 0");
                                } elseif ($parityMode[$posKey] === 'odd') {
                                    // 必须为奇数：对 2 取模不等于 0
                                    $query->whereRaw("code{$i} % 2 != 0");
                                }
                            }
                        }
                    }

                    // 3. 前区连号模式过滤 (基于 basic_dlt 表的 consecutive_count 字段)
                    if ($request->has('consecutiveMode')) {
                        $cMode = $request->input('consecutiveMode');
                        if ($cMode === 'must') {
                            // 必须有连号：连号数大于等于 1 (即至少有一组二连号)
                            $query->where('consecutive_count', '>=', 1);
                        } elseif ($cMode === 'none') {
                            // 必须无连号：连号数等于 0
                            $query->where('consecutive_count', 0);
                        }
                    }

                    // 4. 前区第一、二位同奇同偶断言拦截
                    if ($request->input('noDoubleEven') === true) {
                        // 不能同时为偶数：排除 (code1是偶 且 code2是偶) 的情况
                        $query->whereRaw("NOT (code1 % 2 = 0 AND code2 % 2 = 0)");
                    }
                    if ($request->input('noDoubleOdd') === true) {
                        // 不能同时为奇数：排除 (code1是奇 且 code2是奇) 的情况
                        $query->whereRaw("NOT (code1 % 2 != 0 AND code2 % 2 != 0)");
                    }

                    // 5. 全局可视化杀红区过滤
                    if ($request->has('killFront') && is_array($request->input('killFront')) && !empty($request->input('killFront'))) {
                        $killFrontArray = array_map('intval', $request->input('killFront'));
                        $query->whereNotIn('code1', $killFrontArray)
                            ->whereNotIn('code2', $killFrontArray)
                            ->whereNotIn('code3', $killFrontArray)
                            ->whereNotIn('code4', $killFrontArray)
                            ->whereNotIn('code5', $killFrontArray);
                    }

                    // 6. 【新增】全局前区胆码过滤断言
                    if ($request->has('danFront') && is_array($request->input('danFront')) && !empty($request->input('danFront'))) {
                        $danFrontArray = array_map('intval', $request->input('danFront'));
                        
                        // 每个胆码都必须出现在 code1 ~ code5 的其中一个位置上
                        foreach ($danFrontArray as $danNum) {
                            $query->where(function ($subQuery) use ($danNum) {
                                $subQuery->where('code1', $danNum)
                                        ->orWhere('code2', $danNum)
                                        ->orWhere('code3', $danNum)
                                        ->orWhere('code4', $danNum)
                                        ->orWhere('code5', $danNum);
                            });
                        }
                    }

                    // 走大盘高性能随机抽样
                    $results = $this->fetchRandomlyWithQuery($query, $take);
                    break;

            default:
                $results = $this->fetchRandomlyWithQuery($query, $take);
        }

        if ($results->isEmpty()) {
            return response()->json(['success' => false, 'message' => '没有符合条件的号码']);
        }

        // 4. 构建返回数据
        $randomData = $results->map(fn($row) => $service->buildRow($row));
        $issue = $this->currentIssue();

        // 5. 自动保存到永久记录表
        $records = [];
        foreach ($results as $row) {
            $records[] = [
                'user_id'       => $user->id,
                'lottery_type'  => 'dlt',
                'is_fushi'      => 0, 
                'issue'         => $issue,
                'mode'          => $type,
                'red_numbers'   => $row->front, 
                'blue_numbers'  => $row->back,  
                'red_dan'       => '',
                'kill_numbers'  => '',
                'ip'            => $ip,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }
        DB::table('user_lotto_records')->insert($records);

        // 6. 原子锁标记推荐池记录，拦截高并发抢占
        $affected = BasicDlt::whereIn('id', $results->pluck('id'))
            ->whereNull('user_id')
            ->update([
                'user_id' => $user->id
            ]);

        if ($affected !== $results->count()) {
            // 在更新的瞬间被极速抢占，直接抛出异常保护数据独占性
            return response()->json(['success' => false, 'message' => '选号已被并发抢占，请重试'], 409);
        }

        return response()->json([
            'success' => true,
            'data'    => $randomData,
            'remain'  => $remaining - $results->count(),
            'first_advantage_top' => $firstAdvTop ?? null 
        ]);
    }

    /**
     * 大乐透百万级大盘带条件高性能随机抽样器
     * 结合了 ID 锚点轰炸与动态 Offset 兜底
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $take 抽取数量
     * @return \Illuminate\Support\Collection
     */
    private function fetchRandomlyWithQuery($query, $take)
    {
        // 1. 缓存获取未分配的最大和最小 ID 边界
        $bounds = Cache::remember('dlt_id_bounds_v2', 30, function() {
            return [
                'min' => BasicDlt::whereNull('user_id')->min('id') ?? 1,
                'max' => BasicDlt::whereNull('user_id')->max('id') ?? 1
            ];
        });

        $minId = $bounds['min'];
        $maxId = $bounds['max'];
        $range = $maxId - $minId;

        if ($range <= 0) {
            return collect();
        }

        // 2. 放大轰炸基数
        // 放大到 400 倍撒网，保证在各种杀号、胆码、奇偶过滤后，依然有极高的命中率
        $seedIds = [];
        $totalNeed = $take * 400; 
        for ($i = 0; $i < $totalNeed; $i++) {
            $seedIds[] = mt_rand($minId, $maxId);
        }
        $seedIds = array_unique($seedIds);

        // 3. 将随机 ID 锚点注入到条件查询中
        $bombQuery = (clone $query)->whereIn('id', $seedIds);
        $results = $bombQuery->get();

        // 4. 如果轰炸命中的有效数据满足需求，打乱后直接截取返回
        if ($results->count() >= $take) {
            return $results->shuffle()->take($take);
        }

        // ==========================================
        // 5. 极端条件兜底逻辑 (彻底修复固定结果问题)
        // ==========================================
        // 当条件极其苛刻（如全奇、全大、且包含特定杀号）导致全盘只剩极少数据时，
        // 锚点轰炸可能落空。此时放弃轰炸，启动 Count + 随机 Offset 模式。
        
        $fallbackQuery = clone $query;
        $totalValid = $fallbackQuery->count();

        // 如果该条件下连一注合法组合都没有，直接返回空
        if ($totalValid == 0) {
            return collect(); 
        }

        // 计算最大可偏移量，防止越界
        $limit = min($take, $totalValid);
        $maxOffset = max(0, $totalValid - $limit);
        
        // 随机切入一个起点，获取连贯但起始位置随机的数据块
        $randomOffset = mt_rand(0, $maxOffset);
        
        $fallbackResults = (clone $query)
            ->offset($randomOffset)
            ->limit($limit)
            ->get();

        // 再次打乱，彻底破坏数据的连续性和主键顺序感
        return $fallbackResults->shuffle();
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
                $reasons[] = "号码连续落在一个ID区间（区间{$currentSegment}），评分下降。";
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
                    $reasons[] = "前三位奇偶形态({$currentP})达成三连，下期概率逐步下降。";
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
            $baseScore -= 10;
            $reasons[] = "全奇偶形态,评分下降10个点";
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

    //近5期号码
    public function hotNumber()
    {
        $limit = 5;

        try {
            $data = DB::table('dlt_lotto_history') // 注意表名
                ->select(['issue', 'front1', 'front2', 'front3', 'front4', 'front5']) 
                ->orderBy('issue', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $data,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '获取热号失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 校验大乐透前区 5 码在 5-9 遗漏值区间内的号码形态（全部大于2过滤）
     */
    public function checkOmission(Request $request)
    {
        // 1. 接收前端传过来的号码数据
        $numbers = $request->input('numbers'); 
        if (is_string($numbers)) {
            $numbers = explode(',', $numbers);
        }

        // 大乐透前区核心只需校验 5 个号码
        if (!$numbers || count($numbers) < 5) {
            return response()->json([
                'success' => false,
                'message' => '号码格式不正确'
            ], 400);
        }

        // 提取前 5 个前区号码，并统一格式化为纯数字字符串（对应数据库 JSON 键）
        $frontBalls = array_map(function($num) {
            return (string)intval($num);
        }, array_slice($numbers, 0, 5));

        try {
            // 2. 获取最新一期大乐透的遗漏值基础数据（✨ 修正：类名统一为 DltLottoHistory）
            $lastRecord = DltLottoHistory::orderBy('issue', 'desc')->first(); 

            // 自动兼容可能存在的大乐透遗漏值字段名
            $omissionField = $lastRecord->front_ball_omission ?? $lastRecord->red_ball_omission ?? null;

            if (!$lastRecord || !$omissionField) {
                return response()->json([
                    'success' => false,
                    'message' => '未找到最新大乐透的遗漏值基础数据'
                ]);
            }

            // 3. 解析遗漏值 JSON
            $omissionMap = is_array($omissionField) 
                ? $omissionField 
                : json_decode($omissionField, true);

            $matchCount = 0;
            $matchedDetails = []; 
            
            // ✨ 修正：变量名修改为符合业务的 Two，代表“假设所有号码的遗漏值都大于 2”
            $allGreaterThanTwo = true; 

            // 4. 遍历当前机选的 5 个前区号码进行多维度形态演算
            foreach ($frontBalls as $ball) {
                $omissionValue = $omissionMap[$ball] ?? 0; // 默认防呆为 0

                // 核心一：校验是否满足全员大于 2。如果只要有一个号码遗漏值是 0、1、2（热码/重码/次热码），该条件破产
                if ($omissionValue <= 2) {
                    $allGreaterThanTwo = false;
                }

                // 核心二：切换大乐透专属的 5-9 遗漏值区间统计
                if ($omissionValue >= 5 && $omissionValue <= 9) {
                    $matchCount++;
                    $displayBall = str_pad($ball, 2, '0', STR_PAD_LEFT);
                    $matchedDetails[] = "前区{$displayBall}(遗漏{$omissionValue})";
                }
            }

            // 5. 组合条件判定与精准 Tip 动态生成
            $isStandard = true;

            if ($allGreaterThanTwo) {
                // 【触发一票否决】5个前区号码全部遗漏值都 > 2，意味着连次热码都没有，全走温冷路线，极其极端
                $tip = "【严重形态异常】当前 5 个前区号码的遗漏值全部大于 2！大乐透历史开奖中，完全不包含任何热码（遗漏0-2）的形态极其罕见。建议重新机选以补充热码防线。";
                $isStandard = false;
            } else {
                // 如果没有触发“全员大于2”的极端情况，则走大乐透 5-9 区间的个数标准判断（你设定要求的 1~3 个）
                if ($matchCount >= 1 && $matchCount <= 3) {
                    $detailStr = implode('、', $matchedDetails);
                    $tip = "【形态达标】当前方案符合黄金规律！前区在 5-9 遗漏值区间的号码共 {$matchCount} 个（标准要求 1~3 个）。涉及号码：{$detailStr}。";
                    $isStandard = true;
                } else {
                    if ($matchCount == 0) {
                        $tip = "【形态异常提示】当前方案中，没有任何一个前区号码的遗漏值处于 5-9 的中开区间。历史大数据表明该区间最少应包含 1~3 个号码，建议补充以平衡整体形态。";
                    } else {
                        $detailStr = implode('、', $matchedDetails);
                        // ✨ 修正：这里的提示文案同步改成 1~3 个标准
                        $tip = "【形态异常提示】当前方案中，遗漏值在 5-9 之间的前区号码多达 {$matchCount} 个（涉及：{$detailStr}），超出了历史高频的 1~3 个标准。号码堆积过密，形态不够均衡。";
                    }
                    $isStandard = false;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'match_count' => $matchCount,
                    'is_standard' => $isStandard,
                    'all_greater_than_two' => $allGreaterThanTwo, // ✨ 同步给前端对应的键名
                    'tip' => $tip
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '大乐透服务器演算遗漏值失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 【新增】大乐透机选池全网大单净化过滤打标（含期号、单期频控、管理员特权与日志留痕）
     */
    public function filterDadan(Request $request)
    {
        $user = $request->user();
        $numbers = $request->input('numbers');
        $issue = $request->input('issue');

        // 1. 参数严格基础验证
        if (empty($issue) || !is_array($numbers)) {
            return response()->json(['success' => false, 'message' => '参数缺失'], 400);
        }

        $count = count($numbers);
        if ($count < 10 || $count > 18) {
            return response()->json(['success' => false, 'message' => '大乐透大底红球限制范围 10 - 18 个'], 400);
        }

        // 2. ⚡【核心需求修改】非管理员才检查当前用户在当前期号下是否已经操作过
        $lockKey = "dlt_dadan_filter_user_{$user->id}_issue_{$issue}";
        if ($user->is_admin != 1 && Cache::has($lockKey)) {
            return response()->json([
                'code' => 403, 
                'success' => false, 
                'message' => "您本期 ({$issue}期) 已执行过大单过滤，每期仅限提交 1 次！"
            ], 403);
        }

        // 3. 确保号码为纯数字并从小到大排序
        $numbers = array_map('intval', $numbers);
        sort($numbers);

        try {
            DB::beginTransaction();

            // 4. ⚡ 批量将涵盖在用户大底中的单式号码的 user_id 标记成 1（从机选池永久隔离）
            $affectedRows = DB::table('basic_dlt')
                ->whereIn('code1', $numbers)
                ->whereIn('code2', $numbers)
                ->whereIn('code3', $numbers)
                ->whereIn('code4', $numbers)
                ->whereIn('code5', $numbers)
                ->whereNull('user_id') // 必须是还未被抽取派发走的干净组合
                ->update([
                    'user_id' => 1, 
                    'updated_at' => now() 
                ]);

            // 5. 将大乐透本次提交的详细数据（含用户名）持久化到日志表
            DB::table('user_dadan_records')->insert([
                'user_id'       => $user->id,
                'username'      => $user->name ?? $user->username ?? '', 
                'lottery_type'  => 'dlt', // 标识为大乐透
                'issue'         => $issue,
                'numbers'       => implode(',', $numbers), 
                'ball_count'    => $count,
                'affected_rows' => $affectedRows,
                'ip'            => $request->ip(),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            DB::commit();

            // 6. ⚡【核心需求修改】过滤成功后，非管理员才写入缓存锁定动作
            if ($user->is_admin != 1) {
                Cache::put($lockKey, true, now()->addDays(3));
            }

            return response()->json([
                'code' => 200, 
                'success' => true, 
                'message' => '大乐透大单过滤成功！' . ($user->is_admin == 1 ? '（管理员特权通道）' : ''),
                'data' => [
                    'affectedRows' => $affectedRows
                ]
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'code' => 500, 
                'success' => false, 
                'message' => '大乐透大单穿透失败: ' . $e->getMessage()
            ], 500);
        }
    }
}