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
        $query = LottoSsqRecommendation::whereNull('ip');

        // 3. 匹配玩法逻辑
        switch ($type) {
            case 'normal':
                $results = $query->whereIn('weight', [0, 1, 2, 3, 4, 5])
                    ->inRandomOrder()->take($take)->get();
                break;

            case 'dan_only':
                $dan = (array)($prefs['dan'] ?? []);
                if (count($dan) < 1 || count($dan) > 5) {
                    return response()->json(['success' => false, 'message' => '胆码数量必须 1–5 个'], 400);
                }
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
                $results = $query->whereIn('front_1', array_keys($firstAdvTop))
                    ->inRandomOrder()->take($take)->get();
                break;

            case 'connect':
                $consecutive = (int)($prefs['serial'] ?? 0);
                if ($consecutive <= 0) return response()->json(['success' => false, 'message' => '请选择连号个数'], 400);
                $results = $query->where('consecutive_count', $consecutive)->inRandomOrder()->take($take)->get();
                break;

            case 'history_sum':
                $excludeCount = (int)$request->input('exclude', 0);
                $excludeSums = $excludeCount > 0 ? DB::table('ssq_lotto_history')->orderByDesc('issue')->limit($excludeCount)->pluck('front_sum')->toArray() : [];
                if (!empty($excludeSums)) $query->whereNotIn('front_sum', $excludeSums);
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
                if (!empty($prefs['first'])) $query->where('front_1', $prefs['first']);
                if (!empty($prefs['last'])) $query->where('front_6', $prefs['last']);
                $results = $query->inRandomOrder()->take($take)->get();
                break;

            default:
                return response()->json(['success' => false, 'message' => '未知机选类型'], 400);
        }

        if ($results->isEmpty()) {
            return response()->json(['success' => false, 'message' => '没有符合条件的号码或数据未更新']);
        }

        // 4. 构建前端返回数据 (修复期号显示)
        $randomData = $results->map(function ($row) use ($last, $posCounts, $hotPairs, $type) {
            $issue = $row->issue;
            if (strlen($issue) < 7) $issue = '20' . $issue;

            $base = [
                'id' => $row->id,
                'issue' => $issue,
                'front_numbers' => $row->front_numbers,
                'back_numbers'  => $row->back_numbers,
            ];

            if (!in_array($type, ['connect', 'history_span'])) {
                $base['features'] = $this->buildFeatures($row, $last, $posCounts, $hotPairs);
            }
            return $base;
        });

        // 5. 自动同步到【统一记录表 user_lotto_records】 (含期号修复)
        $records = [];
        foreach ($results as $row) {
            $issue = $row->issue;
            if (strlen($issue) < 7) {
                $issue = '20' . $issue;
            }

            $records[] = [
                'user_id'       => $user->id,
                'lottery_type'  => 'ssq',
                'is_fushi'      => 0,
                'issue'         => $issue,
                'mode'          => $type,
                'red_numbers'   => $row->front_numbers,
                'blue_numbers'  => $row->back_numbers,
                'red_dan'       => '',
                'ip'            => $ip,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }
        DB::table('user_lotto_records')->insert($records);

        // 更新机选库标记
        LottoSsqRecommendation::whereIn('id', $results->pluck('id'))->update([
            'ip' => $ip,
            'user_id' => $user->id,
            'mode' => $type
        ]);

        return response()->json([
            'success' => true,
            'data' => $randomData,
            'remain' => $remaining - $results->count()
        ]);
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

    // --- 辅助方法与原有逻辑保持一致 ---

    public function download(Request $request)
    {
        $ip = $request->ip();
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
            'sum_same'  => $row->front_sum == $last['front_sum'],
            'zone_same' => $zoneSame,
            'cold_numbers' => $cold,
            'pos_appear'   => $posAppear,
            'low_pos_nums' => $lowPosNums,
            'pair_hit'   => !empty($hitPairs),
            'pair_score' => $pairScore,
            'hit_pairs'  => $hitPairs,
            'weight'     => $row->weight,
            'continue_count' => $row->consecutive_count
        ];
    }

    public function pairStats()
    {
        $data = Cache::remember('ssq_pair_stats_100', 3600, function () {
            $rows = DB::table('ssq_lotto_history')->orderByDesc('issue')->limit(100)->get();
            $counts = [];
            foreach ($rows as $row) {
                $numbers = [$row->front1, $row->front2, $row->front3, $row->front4, $row->front5, $row->front6];
                sort($numbers);
                for ($i = 0; $i < 5; $i++) {
                    for ($j = $i + 1; $j < 6; $j++) {
                        $key = $numbers[$i] . ',' . $numbers[$j];
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                }
            }
            return $counts;
        });
        return response()->json(['data' => $data]);
    }

    private function getHotPairs($minCount = 6)
    {
        $pairStats = Cache::get('ssq_pair_stats_100', []);
        return array_filter($pairStats, fn($count) => $count >= $minCount);
    }
}