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
     * 功能：机选号码 + 自动同步到永久记录表
     */
    public function pick(Request $request)
    {
        // 直接获取用户，不需要 Auth::check()，因为中间件已经帮你挡住了游客
        $user = $request->user(); 
        $ip = $request->ip();

        // 1. 限制次数 (针对用户 ID 限制，比 IP 更精准)
        $count = LottoSsqRecommendation::where('user_id', $user->id)->count();
        $maxPerUser = 500;
        $remaining = $maxPerUser - $count;

        if ($remaining <= 0) {
            return response()->json(['success' => false, 'message' => '今日机选次数已达上限'], 403);
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

        // 2. 匹配玩法逻辑
        switch ($type) {
            case 'normal':
                $results = LottoSsqRecommendation::whereNull('ip')
                    ->whereIn('weight', [0, 1, 2, 3, 4, 5])
                    ->inRandomOrder()->take($take)->get();
                break;

            case 'dan_only':
                $dan = (array)($prefs['dan'] ?? []);
                if (count($dan) < 1 || count($dan) > 5) {
                    return response()->json(['success' => false, 'message' => '胆码数量必须 1–5 个'], 400);
                }
                $query = LottoSsqRecommendation::whereNull('ip');
                foreach ($dan as $num) {
                    $query->where(function ($q) use ($num) {
                        $q->orWhere('front_1', $num)->orWhere('front_2', $num)->orWhere('front_3', $num)
                          ->orWhere('front_4', $num)->orWhere('front_5', $num)->orWhere('front_6', $num);
                    });
                }
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            case 'first_advantage':
                $firstCounts = Cache::remember('ssq_last80_first_counts', 60, function () {
                    return DB::table('ssq_lotto_history')->orderByDesc('id')->limit(80)->pluck('front1')->countBy()->toArray();
                });
                arsort($firstCounts);
                $firstAdvTop = array_slice($firstCounts, 0, 5, true);
                $results = LottoSsqRecommendation::whereNull('ip')
                    ->whereIn('front_1', array_keys($firstAdvTop))
                    ->inRandomOrder()->take($take)->get();
                break;

            case 'connect':
                $consecutive = (int)($prefs['serial'] ?? 0);
                if ($consecutive <= 0) return response()->json(['success' => false, 'message' => '请选择连号个数'], 400);
                $results = LottoSsqRecommendation::whereNull('ip')->where('consecutive_count', $consecutive)->inRandomOrder()->take($take)->get();
                break;

            case 'history_sum':
                $excludeCount = (int)$request->input('exclude', 0);
                $excludeSums = $excludeCount > 0 ? DB::table('ssq_lotto_history')->orderByDesc('issue')->limit($excludeCount)->pluck('front_sum')->toArray() : [];
                $query = LottoSsqRecommendation::whereNull('ip');
                if (!empty($excludeSums)) $query->whereNotIn('front_sum', $excludeSums);
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            case 'history_span':
                $exclude = (array)$request->input('exclude', []);
                $query = LottoSsqRecommendation::whereNull('ip');
                if (!empty($exclude)) $query->whereNotIn('span', $exclude);
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            case 'odd_even':
                if (empty($prefs['odd_even'])) return response()->json(['success' => false, 'message' => '请选择奇偶比'], 400);
                [$odd, $even] = explode(':', $prefs['odd_even']);
                $results = LottoSsqRecommendation::whereNull('ip')->where('odd_count', (int)$odd)->where('even_count', (int)$even)->inRandomOrder()->take($take)->get();
                break;

            case 'first_last':
                $query = LottoSsqRecommendation::whereNull('ip');
                if (!empty($prefs['first'])) $query->where('front_1', $prefs['first']);
                if (!empty($prefs['last'])) $query->where('front_6', $prefs['last']);
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            default:
                return response()->json(['success' => false, 'message' => '未知机选类型'], 400);
        }

        if ($results->isEmpty()) {
            return response()->json(['success' => false, 'message' => '没有符合条件的号码']);
        }

        // 3. 构建前端返回数据
        $randomData = $results->map(function ($row) use ($last, $posCounts, $hotPairs, $type) {
            $base = [
                'id' => $row->id,
                'issue' => $row->issue,
                'front_numbers' => $row->front_numbers,
                'back_numbers'  => $row->back_numbers,
            ];

            if (in_array($type, ['connect', 'history_span'])) {
                return $base;
            }

            $base['features'] = $this->buildFeatures($row, $last, $posCounts, $hotPairs);
            return $base;
        });

        // 4. 绑定 IP 和 UserID (临时推荐表)
        $updateData = ['ip' => $ip, 'mode' => $type];
        if ($user) {
            $updateData['user_id'] = $user->id;
        }
        // 2. 绑定数据到推荐表
        LottoSsqRecommendation::whereIn('id', $results->pluck('id'))->update([
            'ip' => $ip,
            'user_id' => $user->id,
            'mode' => $request->input('type', 'normal')
        ]);

        // 3. 自动同步到永久选号表
        $records = $results->map(function ($row) use ($user, $request) {
            return [
                'user_id'       => $user->id,
                'lottery_type'  => 'ssq',
                'issue'         => $row->issue,
                'front_numbers' => $row->front_numbers,
                'back_numbers'  => $row->back_numbers,
                'is_win'        => 0,
                'mode'          => $request->input('type', 'normal'),
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        })->toArray();

        DB::table('user_lotto_selections')->insert($records);

        // 4. 返回响应
        $response = [
            'success' => true,
            'data' => $randomData,
            'remain' => $remaining - count($records)
        ];

        // 【关键新增】如果是首红优势机选，把计算好的排行榜塞进返回结果
        if ($type === 'first_advantage' && isset($firstAdvTop)) {
            $response['first_advantage_top'] = $firstAdvTop;
        }

        return response()->json($response);
    }

    /**
     * 下载当前 IP 的号码
     */
    public function download(Request $request)
    {
        $ip = $request->ip();
        if (empty($ip)) return response()->json(['success' => false, 'message' => '获取失败'], 400);

        $list = LottoSsqRecommendation::where('ip', $ip)->orderBy('id')->get();
        if ($list->isEmpty()) return response()->json(['success' => false, 'message' => '暂无可下载数据'], 404);

        $content = '';
        foreach ($list as $i => $row) {
            $content .= sprintf("%02d. 红球:%s | 蓝球:%s\n", $i + 1, $row->front_numbers, $row->back_numbers);
        }

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ssq.txt"'
        ]);
    }

    /**
     * 双色球号码分布统计接口
     */
    public function numberDistribution(Request $request)
    {
        $periods = (int) $request->query('periods', 50);
        $issues = DB::table('ssq_lotto_history')->orderByDesc('issue')->limit($periods)->pluck('issue')->toArray();

        if (empty($issues)) return response()->json(['code' => 200, 'data' => []]);

        $positions = ['front1', 'front2', 'front3', 'front4', 'front5', 'front6'];
        $result = [];

        foreach ($positions as $pos) {
            $counts = DB::table('ssq_lotto_history')
                ->select($pos . ' as number', DB::raw('COUNT(*) as count'))
                ->whereIn('issue', $issues)
                ->groupBy($pos)->orderBy($pos)->get()->toArray();

            $result[] = array_map(function ($item) {
                return ['number' => $item->number, 'count' => $item->count];
            }, $counts);
        }

        return response()->json(['code' => 200, 'data' => $result]);
    }

    public function lastIssue(Request $request)
    {
        $last = DB::table('ssq_lotto_history')->orderByDesc('id')->first();
        if (!$last) return response()->json(['success' => false, 'message' => '暂无历史开奖数据']);

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
                'front_numbers' => $last->front_numbers,
                'back_numbers'  => $last->back_numbers,
                'features' => [
                    'cold_numbers'   => $maxMissNums,
                    'pos_miss_nums'  => $posMissNums,
                    'continue_count' => $last->continue_count ?? 0
                ]
            ]
        ]);
    }

    public function pairStats()
    {
        return response()->json(['data' => $this->getPairStatsData()]);
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

    private function getHotPairs($minCount = 6)
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

    private function getLastIssueFeatures()
    {
        return Cache::remember('ssq_last_issue_features', 60, function () {
            $last = DB::table('ssq_lotto_history')->orderByDesc('id')->first();
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
        $reds = array_map('intval', explode(',', $row->front_numbers));
        sort($reds);
        $cold = array_values(array_intersect($reds, $last['cold_numbers']));
        $zoneSame = ($row->zone1_count == $last['zone_ratio'][0] && $row->zone2_count == $last['zone_ratio'][1] && $row->zone3_count == $last['zone_ratio'][2]);

        $posAppear = [];
        $lowPosNums = [];
        for ($i = 1; $i <= 6; $i++) {
            $num = $row->{'front_' . $i};
            $count = $posCounts[$i][$num] ?? 0;
            $posAppear[] = $count;
            if ($count === 0) $lowPosNums[] = $num;
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
                    $hitPairs[] = ['pair' => $key, 'count' => $hotPairs[$key]];
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
            'weight'     => $row->weight,
            'continue_count' => $row->consecutive_count
        ];
    }
}