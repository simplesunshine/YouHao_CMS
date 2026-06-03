<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\LottoSsqRecommendation;
use App\Models\BasicSsq;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\LotterySetting;
use App\Services\SsqService;
use App\Models\SsqLottoHistory;

class SsqController extends Controller
{

    protected $ssqService;
        
    // 构造函数注入 Service
    public function __construct(SsqService $ssqService)
    {
        $this->ssqService = $ssqService;
    }
    /**
     * 内部限流逻辑：基于 User ID (1秒1次)
     */
    private function checkRateLimit($user)
    {
        $cacheKey = 'ssq_pick_limit_user_' . $user->id;
        if (Cache::has($cacheKey)) {
            return false;
        }
        Cache::put($cacheKey, 1, 1);
        return true;
    }

    /**
     * 通用机选接口
     * 功能：机选号码 + 自动同步到统一记录表 user_lotto_records (含期号修复)
     */
    public function pick(Request $request)
    {
        $user = $request->user(); 
        $ip = $request->ip();

        // 1. 频率限制
        if (!$this->checkRateLimit($user)) {
            return response()->json(['success' => false, 'message' => '操作太频繁'], 429);
        }

        // 2. 限制次数 (基于新表 user_lotto_records 统计)
        $count = DB::table('user_lotto_records')
            ->where('user_id', $user->id)
            ->where('lottery_type', 'ssq')
            ->whereDate('created_at', now()->toDateString())
            ->count();
            
        $maxPerUser = 500;
        $remaining = $maxPerUser - $count;

        if ($remaining <= 0) {
            return response()->json(['success' => false, 'message' => '今日机选次数已达上限', 'remain' => 0], 403);
        }

        $take = min(5, $remaining);
        $type  = $request->input('type', 'normal');
        $prefs = $request->input('prefs', []);

        // 加载特征计算依赖
        $last = $this->getLastIssueFeatures();
        $posCounts = $this->getPosCounts();
        $hotPairs = $this->getHotPairs(6);

        if (!$last && !in_array($type, ['connect', 'history_span'])) {
            return response()->json(['success' => false, 'message' => '暂无历史开奖数据']);
        }

        $results = collect();
        $query = BasicSsq::whereNull('user_id');

        // 3. 匹配玩法逻辑
        switch ($type) {
            case 'normal':
                $results = $query->inRandomOrder()->take($take)->get();
                break;
            case 'kill_pick':
                $killFrontStr = $prefs['kill_front'] ?? '';
                if (!empty($killFrontStr)) {
                    $killFrontArray = array_filter(array_map('intval', explode(',', $killFrontStr)));
                    if (!empty($killFrontArray)) {
                        $query->whereNotIn('code1', $killFrontArray)
                              ->whereNotIn('code2', $killFrontArray)
                              ->whereNotIn('code3', $killFrontArray)
                              ->whereNotIn('code4', $killFrontArray)
                              ->whereNotIn('code5', $killFrontArray)
                              ->whereNotIn('code6', $killFrontArray);
                    }
                }
                $results = $query->inRandomOrder()->take($take)->get();
                break;                
            case 'dan_only':
                $dan = (array)($prefs['dan'] ?? []);
                if (count($dan) < 1 || count($dan) > 5) {
                    return response()->json(['success' => false, 'message' => '胆码数量必须 1–5 个'], 400);
                }
                foreach ($dan as $num) {
                    $query->where(function ($q) use ($num) {
                        $q->orWhere('code1', $num)->orWhere('code2', $num)->orWhere('code3', $num)
                          ->orWhere('code4', $num)->orWhere('code5', $num)->orWhere('code6', $num);
                    });
                }
                $results = $query->inRandomOrder()->take($take)->get();
                break;
            case 'first_advantage':
                // 1. 从缓存或数据库计算最近 80 期的首红分布
                $firstCounts = Cache::remember('ssq_last80_first_counts', 60, function () {
                    // 确保字段名与你数据库一致，这里使用的是 front1
                    return DB::table('ssq_lotto_history')
                        ->orderByDesc('id')
                        ->limit(80)
                        ->pluck('front1')
                        ->countBy()
                        ->toArray();
                });

                arsort($firstCounts);
                // 2. 截取前 5 名作为“优势排行”
                $firstAdvTop = array_slice($firstCounts, 0, 5, true);
                
                // 3. 在推荐库中筛选第一位红球属于这 Top5 的号码
                $results = $query->whereIn('code1', array_keys($firstAdvTop))
                    ->inRandomOrder()
                    ->take($take)
                    ->get();
                break;

            case 'connect':
                $consecutive = (int)($prefs['serial'] ?? 0);
                if ($consecutive <= 0) return response()->json(['success' => false, 'message' => '请选择连号个数'], 400);
                $results = $query->where('consecutive_count', $consecutive)->inRandomOrder()->take($take)->get();
                break;

            // --- ⭐ 新增：双色球包含近期和值逻辑 ---
            case 'include_sum':
                // 获取前端传来的范围期数，对应前端的 pickerValue[0]
                // 注意：前端传的参数名是 exclude (为了兼容老接口字段)，后端这里直接读取
                $includeCount = (int)$request->input('exclude', 10); 
                
                // 从历史开奖表提取最近 N 期的和值作为“热点池”
                $includeSums = DB::table('ssq_lotto_history')
                    ->orderByDesc('issue')
                    ->limit($includeCount)
                    ->pluck('sum')
                    ->unique()
                    ->toArray();

                if (!empty($includeSums)) {
                    // 仅从基础库中筛选和值在“热点池”内的记录
                    $query->whereIn('sum', $includeSums);
                } else {
                    return response()->json(['success' => false, 'message' => '无法提取历史和值特征'], 400);
                }
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            case 'history_sum':
                $excludeCount = (int)$request->input('exclude', 0);
                $excludeSums = $excludeCount > 0 ? DB::table('ssq_lotto_history')->orderByDesc('issue')->limit($excludeCount)->pluck('sum')->toArray() : [];
                if (!empty($excludeSums)) $query->whereNotIn('sum', $excludeSums);
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            case 'history_span':
                $exclude = (array)$request->input('exclude', []);
                if (!empty($exclude)) $query->whereNotIn('span', $exclude);
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            case 'odd_even':
                if (empty($prefs['odd_even'])) return response()->json(['success' => false, 'message' => '请选择奇偶比'], 400);
                [$odd, $even] = explode(':', $prefs['odd_even']);
                $results = $query->where('odd_count', (int)$odd)->where('even_count', (int)$even)->inRandomOrder()->take($take)->get();
                break;

            case 'first_last':
                if (!empty($prefs['first'])) $query->where('code1', $prefs['first']);
                if (!empty($prefs['last'])) $query->where('code6', $prefs['last']);
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            default:
                return response()->json(['success' => false, 'message' => '未知机选类型'], 400);
        }

        if ($results->isEmpty()) {
            return response()->json(['success' => false, 'message' => '没有符合条件的号码或数据未更新']);
        }

        $issue = $this->currentIssue();
        // 4. 构建前端返回数据 (修复期号显示)
        $randomData = $results->map(function ($row) use ($last, $posCounts, $hotPairs, $type, $issue) {

            $base = [
                'id' => $row->id,
                'issue' => $issue,
                'front_numbers' => $row->front,
                'back_numbers'  => $row->back,
            ];

            if (!in_array($type, ['connect', 'history_span'])) {
                $base['features'] = $this->buildFeatures($row, $last, $posCounts, $hotPairs);
            }
            return $base;
        });

        // 5. 自动同步到【统一记录表 user_lotto_records】 (含期号修复)
        $records = [];
        foreach ($results as $row) {
            $records[] = [
                'user_id'       => $user->id,
                'lottery_type'  => 'ssq',
                'is_fushi'      => 0,
                'issue'         => $issue,
                'mode'          => $type,
                'red_numbers'   => $row->front,
                'blue_numbers'  => $row->back,
                'red_dan'       => '',
                'ip'            => $ip,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }
        DB::table('user_lotto_records')->insert($records);

        // 更新机选库标记
            BasicSsq::whereIn('id', $results->pluck('id'))->update([
            'user_id' => $user->id
        ]);
        
        // 4. 构建最终响应结构
        $response = [
            'success' => true,
            'data' => $randomData,
            'remain' => $remaining - $results->count()
        ];

        // --- 修复点：确保变量名与上面定义的 $firstAdvTop 一致，并赋值给前端期待的 'first_advantage_top' ---
        if ($type === 'first_advantage') {
            // 注意：前端代码里用的是 res.data.first_advantage_top
            $response['first_advantage_top'] = $firstAdvTop ?? [];
        }

        return response()->json($response);

    }

    /**
     * 获取当前期号（缓存5分钟）
     */
    private function currentIssue()
    {
        return \Illuminate\Support\Facades\Cache::remember('ssq_current_issue_for_pick', 300, function () {
            // 获取最新一期
            $issue = LotterySetting::where('type', 1)
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
        $last = DB::table('ssq_lotto_history')->orderByDesc('id')->first();
        if (!$last) return response()->json(['success' => false, 'message' => '暂无历史开奖数据']);
        
        $issue = $last->issue;
        if (strlen($issue) < 7) $issue = '20' . $issue;

        $maxMissNums = json_decode($last->red_max_miss_json, true) ?? [];
        $posMissRaw = json_decode($last->red_position_80_miss_json, true) ?? [];
        $posMissNums = [];
        foreach ($posMissRaw as $nums) {
            if (!empty($nums)) $posMissNums = array_merge($posMissNums, $nums);
        }
        $posMissNums = array_values(array_unique($posMissNums));

        return response()->json([
            'success' => true,
            'data' => [
                'issue'         => $issue,
                'front_numbers' => $last->front,
                'back_numbers'  => $last->back,
                'features' => [
                    'cold_numbers'   => $maxMissNums,
                    'pos_miss_nums'  => $posMissNums,
                    'continue_count' => $last->continue_count ?? 0
                ]
            ]
        ]);
    }

    private function getLastIssueFeatures()
    {
        return Cache::remember('ssq_last_issue_features', 60, function () {
            $last = DB::table('ssq_lotto_history')->orderByDesc('id')->first();
            if (!$last) return null;
            return [
                'span' => $last->span,
                'sum'  => $last->sum,
                'zone_ratio' => explode(':', $last->zone_ratio),
                'cold_numbers' => json_decode($last->next_red_max_miss_json, true) ?? []
            ];
        });
    }

    private function getPosCounts()
    {
        return Cache::remember('ssq_last80_pos_counts', 60, function () {
            $rows = DB::table('ssq_lotto_history')->orderByDesc('id')->limit(80)->select(['front1', 'front2', 'front3', 'front4', 'front5', 'front6'])->get();
            $counts = [];
            for ($i = 1; $i <= 6; $i++) $counts[$i] = [];
            foreach ($rows as $row) {
                for ($i = 1; $i <= 6; $i++) {
                    $num = $row->{'front' . $i};
                    $counts[$i][$num] = ($counts[$i][$num] ?? 0) + 1;
                }
            }
            return $counts;
        });
    }

    private function buildFeatures($row, $last, $posCounts, $hotPairs)
    {
        $reds = array_map('intval', explode(',', $row->front));
        sort($reds);
        $cold = array_values(array_intersect($reds, $last['cold_numbers']));
        $zoneSame = ($row->zone1_count == $last['zone_ratio'][0] && $row->zone2_count == $last['zone_ratio'][1] && $row->zone3_count == $last['zone_ratio'][2]);
        $posAppear = [];
        $lowPosNums = [];
        for ($i = 1; $i <= 6; $i++) {
            $num = $row->{'code' . $i};
            $count = $posCounts[$i][$num] ?? 0;
            $posAppear[] = $count;
            if ($count === 0) $lowPosNums[] = $num;
        }
        $pairScore = 0;
        $hitPairs = [];
        for ($i = 0; $i < count($reds) - 1; $i++) {
            for ($j = $i + 1; $j < count($reds); $j++) {
                $key = $reds[$i] . ',' . $reds[$j];
                if (isset($hotPairs[$key])) {
                    $pairScore += $hotPairs[$key];
                    $hitPairs[] = ['pair' => $key, 'count' => $hotPairs[$key]];
                }
            }
        }
        return [
            'span_same' => $row->span == $last['span'],
            'sum_same'  => $row->sum == $last['sum'],
            'zone_same' => $zoneSame,
            'cold_numbers' => $cold,
            'pos_appear'   => $posAppear,
            'low_pos_nums' => $lowPosNums,
            'pair_hit'   => !empty($hitPairs),
            'pair_score' => $pairScore,
            'hit_pairs'  => $hitPairs,
            'continue_count' => $row->consecutive_count
        ];
    }

    public function pairStats()
    {
        return response()->json(['data' => $this->getPairStatsData()]);
    }


    /**
     * 获取双色球号码分布趋势（冷热度分析）
     */
        public function numberDistribution(Request $request)
    {
        $limit = $request->input('limit', 30);
        
        // 调用 Service 层的纯净数据
        $data = $this->ssqService->getNumberDistribution($limit);

        if (!$data) {
            return response()->json(['success' => false, 'message' => '暂无历史数据']);
        }

        return response()->json([
            'success' => true,
            'data'    => $data
        ]);
    }

    public function getHotPairs($minCount = 6)
    {
        return Cache::remember("ssq_hot_pairs_100_{$minCount}", 3600, function () use ($minCount) {
            $pairStats = $this->getPairStatsData();
            $hotPairs = [];
            foreach ($pairStats as $key => $count) {
                if ($count >= $minCount) $hotPairs[$key] = $count;
            }
            return $hotPairs;
        });
    }

    private function getPairStatsData()
    {
        return Cache::remember('ssq_pair_stats_100', 3600, function () {
            $rows = DB::table('ssq_lotto_history')->orderByDesc('issue')->limit(100)->get();
            $counts = [];
            foreach ($rows as $row) {
                $numbers = [$row->front1, $row->front2, $row->front3, $row->front4, $row->front5, $row->front6];
                sort($numbers);
                $len = count($numbers);
                for ($i = 0; $i < $len - 1; $i++) {
                    for ($j = $i + 1; $j < $len; $j++) {
                        if ($numbers[$i] == $numbers[$j]) continue;
                        $key = $numbers[$i] . ',' . $numbers[$j];
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                }
            }
            return $counts;
        });
    }



    /**
     * 深度演算评分报告
     * 整合：热号、重号、极端重号、连号复刻、形态拦截、遗漏规避、历史重号走势、前三位奇偶拦截
     */
    public function score(Request $request)
    {
        $id = $request->input('id');
        if ($id) {
            $row = DB::table('basic_ssq')->where('id', $id)->first();
        } else {
            $frontNumbers = $request->input('front_numbers');
            $row = DB::table('basic_ssq')->where('front', $frontNumbers)->first();
        }

        // 2. 获取历史数据 (确保获取足够的数据进行比对)
        $recentHistory = DB::table('ssq_lotto_history')->orderBy('id', 'desc')->limit(6)->get();
        if ($recentHistory->isEmpty()) return response()->json(['success' => false, 'message' => '历史数据为空']);
        
        $latestHistory = $recentHistory->get(0); // 上期
        $preHistory = $recentHistory->get(1);    // 上上期

        // --- 基础数据准备 ---
        $currentReds = [
            (int)$row->code1, (int)$row->code2, (int)$row->code3, 
            (int)$row->code4, (int)$row->code5, (int)$row->code6
        ];
        sort($currentReds); 

        $lastReds = [
            (int)$latestHistory->front1, (int)$latestHistory->front2, (int)$latestHistory->front3, 
            (int)$latestHistory->front4, (int)$latestHistory->front5, (int)$latestHistory->front6
        ];
        sort($lastReds);

        // --- 综合评分变量 ---
        $reasons = [];
        $baseScore = 95;

        // --- 核心逻辑 A：计算近6期高频热号 ---
        $allRecentReds = [];
        foreach ($recentHistory as $h) {
            $allRecentReds = array_merge($allRecentReds, [
                (int)$h->front1, (int)$h->front2, (int)$h->front3, 
                (int)$h->front4, (int)$h->front5, (int)$h->front6
            ]);
        }
        $counts = array_count_values($allRecentReds);
        $hotNumbers = array_keys(array_filter($counts, fn($v) => $v >= 2));
        $hotIntersect = array_intersect($currentReds, $hotNumbers);

        // --- 核心逻辑 B：重号（邻期重复）多维拦截 ---
        $currentDuplicateWithLast = array_intersect($currentReds, $lastReds);
        $currentDupCount = count($currentDuplicateWithLast);
        $lastSelfDupCount = (int)$latestHistory->duplicate_count;

        // --- 核心逻辑 C：连号复刻拦截 ---
        $lastConsecutiveSets = [];
        $tempSet = [$lastReds[0]];
        for ($i = 1; $i < count($lastReds); $i++) {
            if ($lastReds[$i] == $lastReds[$i - 1] + 1) {
                $tempSet[] = $lastReds[$i];
            } else {
                if (count($tempSet) >= 2) $lastConsecutiveSets[] = $tempSet;
                $tempSet = [$lastReds[$i]];
            }
        }
        if (count($tempSet) >= 2) $lastConsecutiveSets[] = $tempSet;

        foreach ($lastConsecutiveSets as $set) {
            if (count(array_intersect($currentReds, $set)) === count($set)) {
                $baseScore -= 60;
                $setStr = implode('-', $set);
                $reasons[] = "连号复刻警告：包含了与上期完全相同的连号组({$setStr})，此类形态连开概率极低。";
                break; 
            }
        }

        // --- 核心逻辑 D：遗漏最大值规避拦截 ---
        $maxMissNums = json_decode($latestHistory->next_red_max_miss_json, true) ?: [];
        if (!empty($maxMissNums)) {
            $missIntersect = array_intersect($currentReds, $maxMissNums);
            if (!empty($missIntersect)) {
                $baseScore -= 5;
                $missStr = implode(',', $missIntersect);
                $reasons[] = "号码中包含历史遗漏最大值({$missStr})。";
            }
        }

        // --- 【新增】核心逻辑 F：前三位奇偶形态拦截 ---
        // 获取当前前三位奇偶性 (1为奇，0为偶)
        $getCurrentParity = function($c1, $c2, $c3) {
            return ($c1 % 2 === 0 ? '偶' : '奇') . ($c2 % 2 === 0 ? '偶' : '奇') . ($c3 % 2 === 0 ? '偶' : '奇');
        };

        $currentP = $getCurrentParity($currentReds[0], $currentReds[1], $currentReds[2]);
        $lastP = $getCurrentParity($latestHistory->front1, $latestHistory->front2, $latestHistory->front3);
        
        if ($currentP === $lastP) {
            // 进一步判断是否与上上期也一致
            if ($preHistory) {
                $preP = $getCurrentParity($preHistory->front1, $preHistory->front2, $preHistory->front3);
                if ($currentP === $preP) {
                    $baseScore -= 90;
                    $reasons[] = "前三位奇偶形态({$currentP})已连续3期重复，极其罕见。";
                } else {
                    $baseScore -= 20;
                    $reasons[] = "前三位奇偶形态({$currentP})与上期雷同。";
                }
            } else {
                $baseScore -= 20;
                $reasons[] = "前三位奇偶形态({$currentP})与上期雷同。";
            }
        }

        // --- 核心逻辑 E：【新增】历史重号空缺检测 ---
        $historyDups = $recentHistory->pluck('duplicate_count')->toArray();
        if (count($historyDups) >= 2 && (int)$historyDups[0] === 0 && (int)$historyDups[1] === 0) {
            if (count($historyDups) >= 3 && (int)$historyDups[2] === 0) {
                $baseScore -= 50;
                $reasons[] = "重号极端空缺：历史近3期重号个数均为0，本期重号喷发概率极高，当前组合需慎重。";
            } else {
                $baseScore -= 20;
                $reasons[] = "重号空缺警告：近2期均未出现重号，重号反弹迹象明显。";
            }
        }

        // --- 3. 最高优先级：特殊全形态拦截 ---
        if ($row->odd_count == 6) {
            return response()->json(['success' => true, 'data' => ['weight' => 60, 'reason' => "全奇数形态，今年至今未出，可适当关注。"]]);
        }
        if ($row->even_count == 6 || $row->odd_count == 0) {
            return response()->json(['success' => true, 'data' => ['weight' => 55, 'reason' => "全偶数形态，深度遗漏，存在反弹可能。"]]);
        }

        // --- 4. 核心扣分逻辑 ---

        // [逻辑 1] 重号拦截与提醒
        if ($currentDupCount === 0) {
            $reasons[] = "下期遇重号概率提升，该组合未现重号。";
        } else {
            $isExtremeDup = ($currentDupCount >= 4);
            $isInertiaDup = ($lastSelfDupCount > 2 && $currentDupCount > 2);
            if ($isExtremeDup || $isInertiaDup) {
                $baseScore -= 50;
                $numsStr = implode(',', $currentDuplicateWithLast);
                if ($isExtremeDup) {
                    $reasons[] = "极端重号风险：与上期重复高达 {$currentDupCount} 个号码({$numsStr})。";
                } else {
                    $reasons[] = "重号惯性拦截：上期已出 {$lastSelfDupCount} 个重号，本组合重复数({$currentDupCount})过多({$numsStr})。";
                }
            }
        }

        // [逻辑 2] 热号连接分析
        if (count($hotIntersect) === 0) {
            $baseScore -= 30;
            $reasons[] = "近6期高频热号在当前组合中完全缺席。";
        }

        // [逻辑 3] 区间比重复拦截
        $currentZoneRatio = "{$row->zone1_count}:{$row->zone2_count}:{$row->zone3_count}";
        if ($currentZoneRatio === $latestHistory->zone_ratio && (int)$latestHistory->continuous_zone_count >= 2) {
            $baseScore -= 50;
            $reasons[] = "区间比（{$currentZoneRatio}）已连续出现2期。";
        }

        // [逻辑 4] 和值个位拦截
        $currentSumTail = (int)$row->sum % 10;
        $lastSumTail = (int)$latestHistory->sum % 10;
        $lastSumTailCount = (int)$latestHistory->continuous_sum_tail;
        $potentialSumTailCount = ($currentSumTail === $lastSumTail) ? $lastSumTailCount + 1 : 1;

        if ($potentialSumTailCount >= 4) {
            return response()->json(['success' => true, 'data' => ['weight' => 10, 'reason' => "和值个位将达成【{$potentialSumTailCount}连开】，风险极大。"]]);
        } elseif ($potentialSumTailCount == 3) {
            $baseScore -= 50;
            $reasons[] = "和值个位已连出2次。";
        }

        // [逻辑 5] 跨度连续拦截
        $currentSpan = (int)$row->span;
        if ($currentSpan === (int)$latestHistory->span && (int)$latestHistory->continuous_span_count >= 2) {
            $baseScore -= 60;
            $reasons[] = "跨度（{$currentSpan}）已连续2期相同。";
        }

        // [逻辑 6] 跨度异常拦截
        if ($row->span < 15) {
            $baseScore -= 20;
            $reasons[] = "跨度过小（<15）。";
        }

        // --- 5. 结果合成 ---
        if (empty($reasons)) {
            $reasons[] = "号码形态分布均衡，各项指标符合常规历史走势规律。";
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
            $query = DB::table('ssq_lotto_history')
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
     * 获取双色球历史首尾号段趋势 (龙头/凤尾)
     * 返回顺序：按期号由小到大 (由旧到新)
     */
    public function edgeHistory(Request $request)
    {
        $limit = $request->query('limit', 20);

        try {
            $data = DB::table('ssq_lotto_history')
                ->select(['issue', 'front1', 'front6', 'span'])
                ->orderBy('issue', 'desc') // 先倒序取最新的 N 期
                ->limit($limit)
                ->get()
                ->reverse() // ⭐ 关键点：将最新的 N 期数据反转，变为顺序排列
                ->values()   // ⭐ 重置索引，确保返回的是纯数组而非对象
                ->map(function ($item) {
                    return [
                        'issue' => $item->issue,
                        'issue_short' => substr($item->issue, -3) . '期',
                        'first' => str_pad($item->front1, 2, '0', STR_PAD_LEFT),
                        'last' => str_pad($item->front6, 2, '0', STR_PAD_LEFT),
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
            $data = DB::table('ssq_lotto_history') // 注意表名
                ->select(['issue', 'front1', 'front2', 'front3', 'front4', 'front5', 'front6']) 
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
     * 校验红球在 6-11 遗漏值区间内的号码形态
     */
    public function checkOmission(Request $request)
    {
        // 1. 接收前端传过来的号码数据
        $numbers = $request->input('numbers'); 
        if (is_string($numbers)) {
            // 如果前端传的是逗号分隔的字符串 "03,06,11,19,25,32,07"，转为数组
            $numbers = explode(',', $numbers);
        }

        if (!$numbers || count($numbers) < 6) {
            return response()->json([
                'success' => false,
                'message' => '号码格式不正确'
            ], 400);
        }

        // 提取前6个红球，并统一格式化为不带前导零的纯数字字符串（因为你的 JSON 键是 "1", "2", "3"）
        $redBalls = array_map(function($num) {
            return (string)intval($num);
        }, array_slice($numbers, 0, 6));

        try {
            // 2. 从双色球历史表中获取最新一期的遗漏值数据
            // 假设你的模型叫 SsqHistory，按期号(issue)或者开奖时间倒序取最新一条
            $lastRecord = SsqLottoHistory::orderBy('issue', 'desc')->first();

            if (!$lastRecord || !$lastRecord->red_ball_omission) {
                return response()->json([
                    'success' => false,
                    'message' => '未找到最新的遗漏值基础数据'
                ]);
            }

            // 3. 解析遗漏值 JSON
            // 如果你在模型里没有设置 casts，这里用 json_decode 转成数组
            $omissionMap = is_array($lastRecord->red_ball_omission) 
                ? $lastRecord->red_ball_omission 
                : json_decode($lastRecord->red_ball_omission, true);

            $matchCount = 0;
            $matchedDetails = []; // 用于存放匹配到的具体号码和遗漏值，让前端提示更详细

            // 4. 遍历当前机选的 6 个红球，计算有多少个号码的遗漏值在 6-11 之间
            foreach ($redBalls as $ball) {
                $omissionValue = $omissionMap[$ball] ?? null;

                if ($omissionValue !== null && $omissionValue >= 6 && $omissionValue <= 11) {
                    $matchCount++;
                    // 补足两位数展示，感官更好
                    $displayBall = str_pad($ball, 2, '0', STR_PAD_LEFT);
                    $matchedDetails[] = "红球{$displayBall}(遗漏{$omissionValue})";
                }
            }

            // 5. 根据“最少1个，最多2个”的业务规则生成精准 Tip 提示
            if ($matchCount >= 1 && $matchCount <= 2) {
                $detailStr = implode('、', $matchedDetails);
                $tip = "【形态达标】当前方案符合黄金规律！红球在 6-11 遗漏值区间的号码共 {$matchCount} 个（标准要求 1~2 个）。涉及号码：{$detailStr}。";
                $isStandard = true;
            } else {
                if ($matchCount == 0) {
                    $tip = "【形态异常提示】当前方案中，没有任何一个红球的遗漏值处于 6-11 的中开区间。历史大数据表明该区间最少应包含 1~2 个号码，当前选号可能会偏向极端，请理性参考。";
                } else {
                    $detailStr = implode('、', $matchedDetails);
                    $tip = "【形态异常提示】当前方案中，遗漏值在 6-11 之间的红球多达 {$matchCount} 个（涉及：{$detailStr}），超出了历史高频的 1~2 个标准标准。号码堆积过密，形态不够均衡。";
                }
                $isStandard = false;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'match_count' => $matchCount,
                    'is_standard' => $isStandard,
                    'tip' => $tip
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '服务器演算遗漏值失败: ' . $e->getMessage()
            ], 500);
        }
    }

}