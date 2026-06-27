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
    public function __construct(SsqService $ssqService = null)
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
            
        $maxPerUser = 5000;
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
                // 1. 获取未分配的最大和最小 ID（缓存30秒，降低IO）
                $bounds = Cache::remember('ssq_id_bounds_v2', 30, function() {
                    return [
                        'min' => BasicSsq::whereNull('user_id')->min('id') ?? 1,
                        'max' => BasicSsq::whereNull('user_id')->max('id') ?? 1
                    ];
                });

                $minId = $bounds['min'];
                $maxId = $bounds['max'];
                $range = $maxId - $minId;

                $results = collect();

                if ($range > 0) {
                    // 2. 扩大抽取池：为了拿 5 注，我们在百万大盘里随机轰炸 100 个不同的 ID 锚点
                    $seedIds = [];
                    $totalNeed = $take * 20; // 轰炸 100 个点，确保绝对离散
                    for ($i = 0; $i < $totalNeed; $i++) {
                        $seedIds[] = mt_rand($minId, $maxId);
                    }
                    $seedIds = array_unique($seedIds);

                    // 3. 一次性进数据库精准过滤：必须是未被分配的，且利用 MySQL 底层索引快速筛选
                    $validRows = BasicSsq::whereIn('id', $seedIds)
                        ->whereNull('user_id')
                        ->get();

                    if ($validRows->isNotEmpty()) {
                        // 4. 关键点：在内存中再次打乱结果集，彻底破坏 MySQL 默认的 ID 有序性
                        $results = $validRows->shuffle()->take($take);
                    }
                }

                // 5. 极端兜底：如果随机轰炸的 100 个点刚好都撞到了空洞或已被选走，导致数量不够
                if ($results->count() < $take) {
                    $needCount = $take - $results->count();
                    // 此时使用轻量级的 limit 补齐，因为有上面的 shuffle，整体依然保持极高离散度
                    $extra = BasicSsq::whereNull('user_id')
                        ->limit($needCount)
                        ->get();
                    $results = $results->merge($extra);
                }
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
                // 【性能优化】复用高效随机抽样器
                $results = $this->fetchRandomlyWithQuery($query, $take);
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
                // 【性能优化】复用高效随机抽样器
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;

            case 'first_advantage':
                $firstCounts = Cache::remember('ssq_last80_first_counts', 60, function () {
                    return DB::table('ssq_lotto_history')
                        ->orderByDesc('id')
                        ->limit(80)
                        ->pluck('front1')
                        ->countBy()
                        ->toArray();
                });

                arsort($firstCounts);
                $firstAdvTop = array_slice($firstCounts, 0, 5, true);
                
                $query->whereIn('code1', array_keys($firstAdvTop));
                // 【性能优化】复用高效随机抽样器
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;

            case 'connect':
                $consecutive = (int)($prefs['serial'] ?? 0);
                if ($consecutive <= 0) return response()->json(['success' => false, 'message' => '请选择连号个数'], 400);
                
                $query->where('consecutive_count', $consecutive);
                // 【性能优化】复用高效随机抽样器
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;

            case 'include_sum':
                $includeCount = (int)$request->input('exclude', 10); 
                
                $includeSums = DB::table('ssq_lotto_history')
                    ->orderByDesc('issue')
                    ->limit($includeCount)
                    ->pluck('sum')
                    ->unique()
                    ->toArray();

                if (!empty($includeSums)) {
                    $query->whereIn('sum', $includeSums);
                } else {
                    return response()->json(['success' => false, 'message' => '无法提取历史和值特征'], 400);
                }
                // 【性能优化】复用高效随机抽样器
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;

            case 'history_sum':
                $excludeCount = (int)$request->input('exclude', 0);
                $excludeSums = $excludeCount > 0 ? DB::table('ssq_lotto_history')->orderByDesc('issue')->limit($excludeCount)->pluck('sum')->toArray() : [];
                if (!empty($excludeSums)) $query->whereNotIn('sum', $excludeSums);
                
                // 【性能优化】复用高效随机抽样器
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;

            case 'history_span':
                $exclude = (array)$request->input('exclude', []);
                if (!empty($exclude)) $query->whereNotIn('span', $exclude);
                
                // 【性能优化】复用高效随机抽样器
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;

            case 'odd_even':
                if (empty($prefs['odd_even'])) return response()->json(['success' => false, 'message' => '请选择奇偶比'], 400);
                [$odd, $even] = explode(':', $prefs['odd_even']);
                
                $query->where('odd_count', (int)$odd)->where('even_count', (int)$even);
                // 【性能优化】复用高效随机抽样器
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;

            case 'first_last':
                if (!empty($prefs['first'])) $query->where('code1', $prefs['first']);
                if (!empty($prefs['last'])) $query->where('code6', $prefs['last']);
                
                // 【性能优化】复用高效随机抽样器
                $results = $this->fetchRandomlyWithQuery($query, $take);
                break;
            // ==================== 🔥 新增：精细化动态条件选号玩法 ====================
            case 'advanced_filter':
                // 1. 各位置红球范围过滤 (P1 ~ P6)
                // 数据库字段通常是 code1 ~ code6 或根据你的实际字段调整
                for ($i = 1; $i <= 6; $i++) {
                    $pKey = "p{$i}";
                    if ($request->has($pKey) && is_array($request->input($pKey)) && !empty($request->input($pKey))) {
                        $query->whereIn("code{$i}", $request->input($pKey));
                    }
                }

                // 2. 全局可视化杀号过滤
                if ($request->has('killNums') && is_array($request->input('killNums')) && !empty($request->input('killNums'))) {
                    $killNums = array_map('intval', $request->input('killNums'));
                    $query->whereNotIn('code1', $killNums)
                          ->whereNotIn('code2', $killNums)
                          ->whereNotIn('code3', $killNums)
                          ->whereNotIn('code4', $killNums)
                          ->whereNotIn('code5', $killNums)
                          ->whereNotIn('code6', $killNums);
                }

                // 3. 各位置奇偶形态过滤 (通过余数模型进行按需拦截)
                if ($request->has('parityMode') && is_array($request->input('parityMode'))) {
                    $parityMode = $request->input('parityMode');
                    for ($i = 1; $i <= 6; $i++) {
                        if (isset($parityMode["p{$i}"])) {
                            $mode = $parityMode["p{$i}"];
                            if ($mode === 'even') {
                                // 必须为偶数
                                $query->whereRaw("code{$i} % 2 = 0");
                            } elseif ($mode === 'odd') {
                                // 必须为奇数
                                $query->whereRaw("code{$i} % 2 != 0");
                            }
                        }
                    }
                }

                // 4. 第一、二位组合奇偶拦截
                if ($request->boolean('noDoubleEven')) {
                    // 不能同时为偶数：意思是 (code1不是偶数) 或者 (code2不是偶数)
                    $query->where(function($q) {
                        $q->whereRaw("code1 % 2 != 0")
                        ->orWhereRaw("code2 % 2 != 0");
                    });
                }

                if ($request->boolean('noDoubleOdd')) {
                    // 不能同时为奇数：意思是 (code1不是奇数) 或者 (code2不是奇数)
                    $query->where(function($q) {
                        $q->whereRaw("code1 % 2 = 0")
                        ->orWhereRaw("code2 % 2 = 0");
                    });
                }

                // 5. 连号过滤模式 (复用你表里的 consecutive_count 字段)
                if ($request->has('consecutiveMode')) {
                    $cMode = $request->input('consecutiveMode');
                    if ($cMode === 'must') {
                        // 必须有连号
                        $query->where('consecutive_count', '>=', 1);
                    } elseif ($cMode === 'none') {
                        // 必须无连号
                        $query->where('consecutive_count', 0);
                    }
                }

                // 6. 调用你写好的高性能随机抽样器在大盘中快速切出 5 注
                $results = $this->fetchRandomlyWithQuery($query, $take);
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
     * 高性能全局随机抽样（百万级表、高并发专用）
     * 结合了 ID 锚点轰炸与动态 Offset 兜底
     * * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $take 抽取数量
     * @return \Illuminate\Support\Collection
     */
    private function fetchRandomlyWithQuery($query, $take)
    {
        // 1. 获取大盘的极值 ID（利用缓存降低 IO）
        $bounds = Cache::remember('ssq_id_bounds_v2', 30, function() {
            return [
                'min' => BasicSsq::whereNull('user_id')->min('id') ?? 1,
                'max' => BasicSsq::whereNull('user_id')->max('id') ?? 1
            ];
        });

        $minId = $bounds['min'];
        $maxId = $bounds['max'];
        $range = $maxId - $minId;

        if ($range <= 0) {
            return collect();
        }

        // 2. 放大轰炸基数
        // 既然有各种附加条件，命中率会降低。我们撒网 2000 个随机点，确保在被条件过滤后，依然能剩下足够的注数
        $seedIds = [];
        $totalNeed = $take * 400; 
        for ($i = 0; $i < $totalNeed; $i++) {
            $seedIds[] = mt_rand($minId, $maxId);
        }
        $seedIds = array_unique($seedIds);

        // 3. 将随机 ID 锚点注入到前端传来的条件查询中
        $bombQuery = (clone $query)->whereIn('id', $seedIds);
        $results = $bombQuery->get();

        // 4. 如果轰炸命中的有效数据满足需求，打乱后直接返回
        if ($results->count() >= $take) {
            return $results->shuffle()->take($take);
        }

        // ==========================================
        // 5. 极端条件兜底逻辑 (非常重要)
        // ==========================================
        // 如果用户选择了极其苛刻的条件（例如：胆码选了5个，或者只要全偶数），
        // 此时全库可能只剩下不到 50 注。那 2000 个随机锚点极有可能一个都砸不中。
        // 这时必须放弃轰炸，改用 Count + 随机 Offset 的方式进行安全抽取。
        
        $fallbackQuery = clone $query;
        $totalValid = $fallbackQuery->count();

        // 如果该条件下连一注都没有，直接返回空
        if ($totalValid == 0) {
            return collect(); 
        }

        // 计算最大可偏移量，防止越界
        $limit = min($take, $totalValid);
        $maxOffset = max(0, $totalValid - $limit);
        
        // 随机切入一个起点，获取数据
        $randomOffset = mt_rand(0, $maxOffset);
        
        $fallbackResults = (clone $query)
            ->offset($randomOffset)
            ->limit($limit)
            ->get();

        // 再次打乱，彻底破坏默认的顺序感
        return $fallbackResults->shuffle();
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
        return Cache::remember('ssq_last80_pos_counts', 600, function () {
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
    // public function score(Request $request)
    // {
    //     $id = $request->input('id');
    //     if ($id) {
    //         $row = DB::table('basic_ssq')->where('id', $id)->first();
    //     } else {
    //         $frontNumbers = $request->input('front_numbers');
    //         $row = DB::table('basic_ssq')->where('front', $frontNumbers)->first();
    //     }

    //     // 2. 获取历史数据 (确保获取足够的数据进行比对)
    //     $recentHistory = DB::table('ssq_lotto_history')->orderBy('id', 'desc')->limit(6)->get();
    //     if ($recentHistory->isEmpty()) return response()->json(['success' => false, 'message' => '历史数据为空']);
        
    //     $latestHistory = $recentHistory->get(0); // 上期
    //     $preHistory = $recentHistory->get(1);    // 上上期

    //     // --- 基础数据准备 ---
    //     $currentReds = [
    //         (int)$row->code1, (int)$row->code2, (int)$row->code3, 
    //         (int)$row->code4, (int)$row->code5, (int)$row->code6
    //     ];
    //     sort($currentReds); 

    //     $lastReds = [
    //         (int)$latestHistory->front1, (int)$latestHistory->front2, (int)$latestHistory->front3, 
    //         (int)$latestHistory->front4, (int)$latestHistory->front5, (int)$latestHistory->front6
    //     ];
    //     sort($lastReds);

    //     // --- 综合评分变量 ---
    //     $reasons = [];
    //     $baseScore = 95;

    //     // --- 核心逻辑 A：计算近6期高频热号 ---
    //     $allRecentReds = [];
    //     foreach ($recentHistory as $h) {
    //         $allRecentReds = array_merge($allRecentReds, [
    //             (int)$h->front1, (int)$h->front2, (int)$h->front3, 
    //             (int)$h->front4, (int)$h->front5, (int)$h->front6
    //         ]);
    //     }
    //     $counts = array_count_values($allRecentReds);
    //     $hotNumbers = array_keys(array_filter($counts, fn($v) => $v >= 2));
    //     $hotIntersect = array_intersect($currentReds, $hotNumbers);

    //     // --- 核心逻辑 B：重号（邻期重复）多维拦截 ---
    //     $currentDuplicateWithLast = array_intersect($currentReds, $lastReds);
    //     $currentDupCount = count($currentDuplicateWithLast);
    //     $lastSelfDupCount = (int)$latestHistory->duplicate_count;

    //     // --- 核心逻辑 C：连号复刻拦截 ---
    //     $lastConsecutiveSets = [];
    //     $tempSet = [$lastReds[0]];
    //     for ($i = 1; $i < count($lastReds); $i++) {
    //         if ($lastReds[$i] == $lastReds[$i - 1] + 1) {
    //             $tempSet[] = $lastReds[$i];
    //         } else {
    //             if (count($tempSet) >= 2) $lastConsecutiveSets[] = $tempSet;
    //             $tempSet = [$lastReds[$i]];
    //         }
    //     }
    //     if (count($tempSet) >= 2) $lastConsecutiveSets[] = $tempSet;

    //     foreach ($lastConsecutiveSets as $set) {
    //         if (count(array_intersect($currentReds, $set)) === count($set)) {
    //             $baseScore -= 60;
    //             $setStr = implode('-', $set);
    //             $reasons[] = "连号复刻警告：包含了与上期完全相同的连号组({$setStr})，此类形态连开概率极低。";
    //             break; 
    //         }
    //     }

    //     // --- 核心逻辑 D：遗漏最大值规避拦截 ---
    //     $maxMissNums = json_decode($latestHistory->next_red_max_miss_json, true) ?: [];
    //     if (!empty($maxMissNums)) {
    //         $missIntersect = array_intersect($currentReds, $maxMissNums);
    //         if (!empty($missIntersect)) {
    //             $baseScore -= 5;
    //             $missStr = implode(',', $missIntersect);
    //             $reasons[] = "号码中包含历史遗漏最大值({$missStr})。";
    //         }
    //     }

    //     // --- 【新增】核心逻辑 F：前三位奇偶形态拦截 ---
    //     // 获取当前前三位奇偶性 (1为奇，0为偶)
    //     $getCurrentParity = function($c1, $c2, $c3) {
    //         return ($c1 % 2 === 0 ? '偶' : '奇') . ($c2 % 2 === 0 ? '偶' : '奇') . ($c3 % 2 === 0 ? '偶' : '奇');
    //     };

    //     $currentP = $getCurrentParity($currentReds[0], $currentReds[1], $currentReds[2]);
    //     $lastP = $getCurrentParity($latestHistory->front1, $latestHistory->front2, $latestHistory->front3);
        
    //     if ($currentP === $lastP) {
    //         // 进一步判断是否与上上期也一致
    //         if ($preHistory) {
    //             $preP = $getCurrentParity($preHistory->front1, $preHistory->front2, $preHistory->front3);
    //             if ($currentP === $preP) {
    //                 $baseScore -= 90;
    //                 $reasons[] = "前三位奇偶形态({$currentP})已连续3期重复，极其罕见。";
    //             } else {
    //                 $baseScore -= 20;
    //                 $reasons[] = "前三位奇偶形态({$currentP})与上期雷同。";
    //             }
    //         } else {
    //             $baseScore -= 20;
    //             $reasons[] = "前三位奇偶形态({$currentP})与上期雷同。";
    //         }
    //     }

    //             // --- 核心逻辑 E：【修复】历史重号空缺与当前选号联动拦截 ---
    //     $historyDups = $recentHistory->pluck('duplicate_count')->toArray();
        
    //     // 只有当大盘历史近 2 期或 3 期的重号个数都为 0 时，才触发大盘趋势预警
    //     if (count($historyDups) >= 2 && (int)$historyDups[0] === 0 && (int)$historyDups[1] === 0) {
            
    //         // 🚨 联动点：如果大盘重号空缺，但用户当前组合“及时补充了重号”（$currentDupCount > 0），则属于顺应走势，不予扣分！
    //         if ($currentDupCount === 0) {
    //             if (count($historyDups) >= 3 && (int)$historyDups[2] === 0) {
    //                 $baseScore -= 50;
    //                 $reasons[] = "重号极端空缺：历史近3期开奖重号个数均为0，本期重号反弹喷发概率极高！当前组合却未包含任何上期重号，需慎重。";
    //             } else {
    //                 $baseScore -= 20;
    //                 $reasons[] = "重号空缺警告：大盘近2期均未出现重号，本期重号反弹迹象明显，当前组合未现重号防线。";
    //             }
    //         } else {
    //             // 【精细化运营】如果大盘空缺，而用户刚好选了重号（比如你中了30），不仅不扣分，还给个正面评语
    //             $reasons[] = "重号反弹捕获：大盘近2期重号空缺，当前组合适时切入上期重号，符合走势反弹规律。";
    //         }
    //     }

    //     // --- 3. 最高优先级：特殊全形态拦截 ---
    //     if ($row->odd_count == 6) {
    //         return response()->json(['success' => true, 'data' => ['weight' => 60, 'reason' => "全奇数形态，今年至今未出，可适当关注。"]]);
    //     }
    //     if ($row->even_count == 6 || $row->odd_count == 0) {
    //         return response()->json(['success' => true, 'data' => ['weight' => 55, 'reason' => "全偶数形态，深度遗漏，存在反弹可能。"]]);
    //     }

    //     // --- 4. 核心扣分逻辑 ---

    //     // [逻辑 1] 重号拦截与提醒
    //     if ($currentDupCount === 0) {
    //         $reasons[] = "下期遇重号概率提升，该组合未现重号。";
    //     } else {
    //         $isExtremeDup = ($currentDupCount >= 4);
    //         $isInertiaDup = ($lastSelfDupCount > 2 && $currentDupCount > 2);
    //         if ($isExtremeDup || $isInertiaDup) {
    //             $baseScore -= 50;
    //             $numsStr = implode(',', $currentDuplicateWithLast);
    //             if ($isExtremeDup) {
    //                 $reasons[] = "极端重号风险：与上期重复高达 {$currentDupCount} 个号码({$numsStr})。";
    //             } else {
    //                 $reasons[] = "重号惯性拦截：上期已出 {$lastSelfDupCount} 个重号，本组合重复数({$currentDupCount})过多({$numsStr})。";
    //             }
    //         }
    //     }

    //     // [逻辑 2] 热号连接分析
    //     if (count($hotIntersect) === 0) {
    //         $baseScore -= 30;
    //         $reasons[] = "近6期高频热号在当前组合中完全缺席。";
    //     }

    //     // [逻辑 3] 区间比重复拦截
    //     $currentZoneRatio = "{$row->zone1_count}:{$row->zone2_count}:{$row->zone3_count}";
    //     if ($currentZoneRatio === $latestHistory->zone_ratio && (int)$latestHistory->continuous_zone_count >= 2) {
    //         $baseScore -= 50;
    //         $reasons[] = "区间比（{$currentZoneRatio}）已连续出现2期。";
    //     }

    //     // [逻辑 4] 和值个位拦截
    //     $currentSumTail = (int)$row->sum % 10;
    //     $lastSumTail = (int)$latestHistory->sum % 10;
    //     $lastSumTailCount = (int)$latestHistory->continuous_sum_tail;
    //     $potentialSumTailCount = ($currentSumTail === $lastSumTail) ? $lastSumTailCount + 1 : 1;

    //     if ($potentialSumTailCount >= 4) {
    //         return response()->json(['success' => true, 'data' => ['weight' => 10, 'reason' => "和值个位将达成【{$potentialSumTailCount}连开】，风险极大。"]]);
    //     } elseif ($potentialSumTailCount == 3) {
    //         $baseScore -= 50;
    //         $reasons[] = "和值个位已连出2次。";
    //     }

    //     // [逻辑 5] 跨度连续拦截
    //     $currentSpan = (int)$row->span;
    //     if ($currentSpan === (int)$latestHistory->span && (int)$latestHistory->continuous_span_count >= 2) {
    //         $baseScore -= 60;
    //         $reasons[] = "跨度（{$currentSpan}）已连续2期相同。";
    //     }

    //     // [逻辑 6] 跨度异常拦截
    //     if ($row->span < 15) {
    //         $baseScore -= 20;
    //         $reasons[] = "跨度过小（<15）。";
    //     }

    //     // --- 5. 结果合成 ---
    //     if (empty($reasons)) {
    //         $reasons[] = "号码形态分布均衡，各项指标符合常规历史走势规律。";
    //     }

    //     return response()->json([
    //         'success' => true,
    //         'data' => [
    //             'weight' => max(0, (int)$baseScore),
    //             'reason' => implode(' ', $reasons)
    //         ]
    //     ]);
    // }

    /**
     * 深度演算评分报告（双色球）
     * 整合：热号、重号、极端重号、连号复刻、形态拦截、遗漏规避、历史重号走势、前三位奇偶拦截
     * 已修复：50期大样本冷热极限拦截（从思路表动态提取）
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

        if (!$row) {
            return response()->json(['success' => false, 'message' => '未找到对应的基础号码组合']);
        }

        // 2. 获取大盘历史数据 (确保获取足够的数据进行比对)
        $recentHistory = DB::table('ssq_lotto_history')->orderBy('id', 'desc')->limit(6)->get();
        if ($recentHistory->isEmpty()) {
            return response()->json(['success' => false, 'message' => '历史开奖数据为空']);
        }
        
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

        // =========================================================================
        // 【核心修复】：从 lottery_settings (思路表) 中动态读取对应期号的 50期冷热号
        // =========================================================================
        // 假设用户当前正在演算预测的是下期（即 latestHistory->issue 的下一期）
        // 我们可以通过预测期号去匹配 lottery_settings 的数据。
        // 为了稳健，我们直接取最新的那一条、或者通过 request 传入预测期号。这里以“当前大盘最新期号的下一期”来精确定位。
        $nextIssueEstimate = $latestHistory->issue + 1; 
        
        $currentSetting = DB::table('lottery_settings')
            ->where('type', 1) // 1代表双色球
            ->where('issue', $nextIssueEstimate)
            ->first();

        // 降级兜底：如果还没创建下一期的思路，则尝试拿思路表最新的一条
        if (!$currentSetting) {
            $currentSetting = DB::table('lottery_settings')
                ->where('type', 1)
                ->orderBy('issue', 'desc')
                ->first();
        }

        $topNums50 = [];
        $bottomNums50 = [];

        if ($currentSetting) {
            $topNums50 = json_decode($currentSetting->top_nums_50, true) ?: [];
            $bottomNums50 = json_decode($currentSetting->bottom_nums_50, true) ?: [];
        }

        // 拦截规则：最热 10 码命中极限拦截
        if (!empty($topNums50)) {
            $topIntersect50 = array_intersect($currentReds, $topNums50);
            $topCount50 = count($topIntersect50);
            // 规则：如果号码与最多的 10 个号交集不在 2-4 个之间（即少于2个或多于4个）
            if ($topCount50 < 2 || $topCount50 > 4) {
                $baseScore -= 70;
                $topStr = implode(',', $topIntersect50);
                $reasons[] = "样本热号拦截：当前组合包含最热号码 {$topCount50} 个({$topStr})，不在的黄金分布区间内，冷热失衡风险极高。"; 
            }
        }

        // 拦截规则：最冷 10 码防线拦截
        if (!empty($bottomNums50)) {
            $bottomIntersect50 = array_intersect($currentReds, $bottomNums50);
            $bottomCount50 = count($bottomIntersect50);
            // 规则：如果组合与最少的 10 个号交集超过 2 个
            if ($bottomCount50 > 2) {
                $baseScore -= 70;
                $bottomStr = implode(',', $bottomIntersect50);
                $reasons[] = "样本冷号防线：当前组合最冷号码（含未出号）高达 {$bottomCount50} 个({$bottomStr})，盲目追冷极易全军覆没。";
            }
        }
        // =========================================================================

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

        // --- 核心逻辑 F：前三位奇偶形态拦截 ---
        $getCurrentParity = function($c1, $c2, $c3) {
            return ($c1 % 2 === 0 ? '偶' : '奇') . ($c2 % 2 === 0 ? '偶' : '奇') . ($c3 % 2 === 0 ? '偶' : '奇');
        };

        $currentP = $getCurrentParity($currentReds[0], $currentReds[1], $currentReds[2]);
        $lastP = $getCurrentParity($latestHistory->front1, $latestHistory->front2, $latestHistory->front3);
        
        if ($currentP === $lastP) {
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

        // --- 核心逻辑 E：历史重号空缺与当前选号联动拦截 ---
        $historyDups = $recentHistory->pluck('duplicate_count')->toArray();
        
        if (count($historyDups) >= 2 && (int)$historyDups[0] === 0 && (int)$historyDups[1] === 0) {
            if ($currentDupCount === 0) {
                if (count($historyDups) >= 3 && (int)$historyDups[2] === 0) {
                    $baseScore -= 50;
                    $reasons[] = "重号极端空缺：历史近3期开奖重号个数均为0，本期重号反反弹喷发概率极高！当前组合却未包含任何上期重号，需慎重。";
                } else {
                    $baseScore -= 20;
                    $reasons[] = "重号空缺警告：大盘近2期均未出现重号，本期重号反弹迹象明显，当前组合未现重号防线。";
                }
            } else {
                $reasons[] = "重号反弹捕获：大盘近2期重号空缺，当前组合适时切入上期重号，符合走势反弹规律。";
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
     * 校验红球在 6-11 遗漏值区间内的号码形态（追加全大遗漏过滤）
     */
    public function checkOmission(Request $request)
    {
        // 1. 接收前端传过来的号码数据
        $numbers = $request->input('numbers'); 
        if (is_string($numbers)) {
            $numbers = explode(',', $numbers);
        }

        if (!$numbers || count($numbers) < 6) {
            return response()->json([
                'success' => false,
                'message' => '号码格式不正确'
            ], 400);
        }

        // 提取前6个红球，并统一格式化为纯数字字符串
        $redBalls = array_map(function($num) {
            return (string)intval($num);
        }, array_slice($numbers, 0, 6));

        try {
            // 2. 获取最新一期的遗漏值基础数据
            $lastRecord = SsqLottoHistory::orderBy('issue', 'desc')->first();

            if (!$lastRecord || !$lastRecord->red_ball_omission) {
                return response()->json([
                    'success' => false,
                    'message' => '未找到最新的遗漏值基础数据'
                ]);
            }

            // 3. 解析遗漏值 JSON
            $omissionMap = is_array($lastRecord->red_ball_omission) 
                ? $lastRecord->red_ball_omission 
                : json_decode($lastRecord->red_ball_omission, true);

            $matchCount = 0;
            $matchedDetails = []; 
            
            // 【新加状态】假设所有号码的遗漏值都大于 1
            $allGreaterThanOne = true; 

            // 4. 遍历当前机选的 6 个红球进行多维度演算
            foreach ($redBalls as $ball) {
                $omissionValue = $omissionMap[$ball] ?? 0; // 默认防呆为 0

                // 核心一：校验是否满足全员大于 1。如果只要有一个红球遗漏值是 0 或 1，该条件即破产
                if ($omissionValue <= 1) {
                    $allGreaterThanOne = false;
                }

                // 核心二：原有的 6-11 区间统计
                if ($omissionValue >= 6 && $omissionValue <= 11) {
                    $matchCount++;
                    $displayBall = str_pad($ball, 2, '0', STR_PAD_LEFT);
                    $matchedDetails[] = "红球{$displayBall}(遗漏{$omissionValue})";
                }
            }

            // 5. 组合条件判定与精准 Tip 动态生成
            $isStandard = true;

            if ($allGreaterThanOne) {
                // 【触发新需求警报】6个红球全部遗漏值都 > 1，意味着没有热号和重号，极为反常
                $tip = "【严重形态异常】当前 6 个红球的遗漏值全部大于 1！历史开奖中，完全不包含热号/重号（遗漏0-1）的期数极罕见。建议重新机选以补充热码防线。";
                $isStandard = false;
            } else {
                // 如果没有触发“全员大于1”的极端情况，则走原有的 6-11 个数标准判断
                if ($matchCount >= 1 && $matchCount <= 3) {
                    $detailStr = implode('、', $matchedDetails);
                    $tip = "【形态达标】当前方案符合黄金规律！红球在 6-11 遗漏值区间的号码共 {$matchCount} 个（标准要求 1~3 个）。涉及号码：{$detailStr}。";
                    $isStandard = true;
                } else {
                    if ($matchCount == 0) {
                        $tip = "【形态异常提示】当前方案中，没有任何一个红球的遗漏值处于 6-11 的中开区间。历史大数据表明该区间最少应包含 1~3 个号码，当前选号可能会偏向极端，请理性参考。";
                    } else {
                        $detailStr = implode('、', $matchedDetails);
                        $tip = "【形态异常提示】当前方案中，遗漏值在 6-11 之间的红球多达 {$matchCount} 个（涉及：{$detailStr}），超出了历史高频的 1~3 个标准。号码堆积过密，形态不够均衡。";
                    }
                    $isStandard = false;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'match_count' => $matchCount,
                    'is_standard' => $isStandard,
                    'all_greater_than_one' => $allGreaterThanOne, // 返回给前端备用
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

    /**
     * 🔥 升级版：双色球网络大单过滤打标 (含期号校验、单期频控与日志留痕)
     * 请求路径：POST /api/ssq/filter-dadan
     */
    public function filterDadan(Request $request)
    {
        $user = $request->user();

        // 1. 严格校验传入的号码和期号参数
        $numbers = $request->input('numbers');
        $issue = $request->input('issue');

        if (empty($issue)) {
            return response()->json(['success' => false, 'message' => '过滤失败：缺少当前期号参数'], 400);
        }

        if (!is_array($numbers) || count($numbers) < 10 || count($numbers) > 16) {
            return response()->json(['success' => false, 'message' => '过滤失败：大单红球数量必须在 10 - 16 个之间'], 400);
        }

        // 2. ⚡【核心需求】检查当前用户在当前期号下是否已经操作过
        $lockKey = "ssq_dadan_filter_user_{$user->id}_issue_{$issue}";
        if (Cache::has($lockKey)) {
            return response()->json([
                'code' => 403,
                'success' => false,
                'message' => "您本期 ({$issue}期) 已经提交过大单过滤，每期仅限操作 1 次！"
            ], 403);
        }

        // 3. 确保号码为纯数字并从小到大排序
        $numbers = array_map('intval', $numbers);
        sort($numbers);

        try {
            DB::beginTransaction();

            // 4. 执行穿透级过滤 SQL
            $affectedRows = DB::table('basic_ssq')
                ->whereIn('code1', $numbers)
                ->whereIn('code2', $numbers)
                ->whereIn('code3', $numbers)
                ->whereIn('code4', $numbers)
                ->whereIn('code5', $numbers)
                ->whereIn('code6', $numbers)
                ->whereNull('user_id') 
                ->update([
                    'user_id' => 1, 
                    'updated_at' => now() 
                ]);

            // 5. ⚡【新增】将双色球本次提交的详细数据（含用户名）持久化到日志表
            DB::table('user_dadan_records')->insert([
                'user_id'       => $user->id,
                'username'      => $user->name ?? $user->username ?? '', // 自动适配模型中的用户名属性
                'lottery_type'  => 'ssq', // 标识为双色球
                'issue'         => $issue,
                'numbers'       => implode(',', $numbers), 
                'ball_count'    => count($numbers),
                'affected_rows' => $affectedRows,
                'ip'            => $request->ip(),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            DB::commit();

            // 6. ⚡【核心需求】过滤成功后，写入缓存锁定动作（缓存保存 3 天，确保覆盖单期开奖周期）
            Cache::put($lockKey, true, now()->addDays(3));

            return response()->json([
                'code' => 200,
                'success' => true,
                'message' => '大单过滤成功！',
                'data' => [
                    'affectedRows' => $affectedRows
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'code' => 500,
                'success' => false,
                'message' => '服务器繁忙，批量过滤打标失败: ' . $e->getMessage()
            ], 500);
        }
    }
}