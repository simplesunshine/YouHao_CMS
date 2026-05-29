<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Services\SsqService; // <--- 引入冷热度分析服务

class SsqFushiController extends Controller
{
    protected $ssqService;

    // 构造函数注入 SsqService
    public function __construct(SsqService $ssqService)
    {
        $this->ssqService = $ssqService;
    }

    /**
     * 内部限流逻辑：基于 User ID
     */
    private function checkRateLimit($user)
    {
        $cacheKey = 'ssq_fushi_limit_user_' . $user->id;

        if (Cache::has($cacheKey)) {
            return false;
        }

        // 设置 1 秒限流
        Cache::put($cacheKey, 1, 1);
        return true;
    }

    /**
     * 1. 双色球普通复式生成（融合冷号智能过滤与概率混入）
     */
    public function normalFushi(Request $request)
    {
        $user = $request->user();
        if (!$this->checkRateLimit($user)) {
            return response()->json(['code' => 429, 'msg' => '操作太频繁，请稍后再试'], 429);
        }

        $redCount = intval($request->input('red_count', 6));
        $blueCount = intval($request->input('blue_count', 1));

        if ($redCount < 6 || $redCount > 20 || $blueCount < 1 || $blueCount > 16) {
            return response()->json(['code' => 400, 'msg' => '球数不合法']);
        }

        // =================【核心优化：冷号统计与分组】=================
        // 默认拉取最近 30 期的分布情况
        $distribution = $this->ssqService->getNumberDistribution(20);
        
        $coldNumbers = []; // 存放出现次数小于3次的红球 (冷号杀号库)
        if ($distribution && isset($distribution['red'])) {
            foreach ($distribution['red'] as $item) {
                if ($item['count'] < 3) {
                    $coldNumbers[] = intval($item['number']);
                }
            }
        }


        // 全集 1-33，排除冷号后得到常规安全池
        $allRedPool = range(1, 33);
        $normalPool = array_values(array_diff($allRedPool, $coldNumbers));

        // 兜底保障：如果冷号杀得太多，导致常规池不够用户选的红球数，就强制还原常规池
        if (count($normalPool) < $redCount) {
            $normalPool = $allRedPool;
            $coldNumbers = []; 
        }
        // ============================================================

        $row = DB::table('ssq_lotto_history')->select('red_ball_omission')->orderByDesc('id')->first();
        if (!$row) return response()->json(['code' => 500, 'msg' => '历史数据不存在']);
        $redOmit = json_decode($row->red_ball_omission, true);

        $try = 0;
        while ($try < 100) {
            $try++;

            // =================【核心优化：动态概率组合】=================
            // 设定随机概率：70% 几率抽到 1（强制含 1 个冷号），30% 几率抽到 0（不含冷号）
            // 如果冷号库本来就空空如也，则直接强制走方案 0
            if (empty($coldNumbers)) {
                $strategy = 0;
            } else {
                // 生成 1-100 的随机数，小于等于 70 则为方案 1，否则为方案 0
                $strategy = (mt_rand(1, 100) <= 60) ? 1 : 0;
            }

            
            $selectedReds = [];

            if ($strategy === 1) {
                // 方案 1：从冷号库抽 1 个 + 从常规池抽 (redCount - 1) 个
                $randomCold = collect($coldNumbers)->shuffle()->take(1)->toArray();
                $randomNormals = collect($normalPool)->shuffle()->take($redCount - 1)->toArray();
                $selectedReds = array_merge($randomCold, $randomNormals);
            } else {
                // 方案 0：全从常规池抽选
                $selectedReds = collect($normalPool)->shuffle()->take($redCount)->toArray();
            }

            // 统一排序，方便校验和入库
            sort($selectedReds);
            // ============================================================

            // 原有的多维过滤条件校验
            $redOmits = array_map(fn($n) => $redOmit[$n] ?? 0, $selectedReds);
            if (max($redOmits) < 6 || min($redOmits) > 1 || !$this->hasPrime($selectedReds) || (max($selectedReds) - min($selectedReds)) < 11) {
                continue;
            }

            $blues = collect(range(1, 16))->shuffle()->take($blueCount)->sort()->values()->toArray();

            // 存入统一记录表
            DB::table('user_lotto_records')->insert([
                'user_id'      => $user->id,
                'lottery_type' => 'ssq',
                'is_fushi'     => 1,
                'issue'        => $request->input('issue'),
                'mode'         => 'normal_fushi',
                'red_dan'      => '',
                'kill_numbers' => implode(',', $coldNumbers), // 顺便可以把这期参与筛选的杀号记录下来
                'red_numbers'  => implode(',', $selectedReds),
                'blue_numbers' => implode(',', $blues),
                'ip'           => $request->ip(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            return response()->json([
                'code' => 200,
                'data' => [
                    'red' => $selectedReds, 
                    'red_omit' => $redOmits, 
                    'blue' => $blues,
                    'strategy_mode' => $strategy // 返回前端，告知这组是纯常规号还是混了冷号
                ]
            ]);
        }

        return response()->json(['code' => 500, 'msg' => '生成失败，请重试']);
    }


    /**
     * 2. 双色球胆拖复式生成（彻底排除系统冷号版）
     */
    public function dantuoFushi(Request $request)
    {
        $user = $request->user();
        if (!$this->checkRateLimit($user)) {
            return response()->json(['code' => 429, 'msg' => '操作太频繁'], 429);
        }

        $tuoCount = intval($request->input('red_count', 6)); // 拖码数量
        $blueCount = intval($request->input('blue_count', 1));
        $danCount = intval($request->input('dan_count', 1));
        
        $preferDanStr = $request->input('prefer_dan', '');
        $preferDans = !empty($preferDanStr) ? explode(',', $preferDanStr) : [];

        if ($danCount < 1 || $danCount > 5 || $tuoCount < 6 || $blueCount < 1) {
            return response()->json(['code' => 400, 'msg' => '参数不合法']);
        }

        // =================【核心优化：获取 30 期冷号作为绝对杀号库】=================
        $distribution = $this->ssqService->getNumberDistribution(20);
        $coldNumbers = []; 
        if ($distribution && isset($distribution['red'])) {
            foreach ($distribution['red'] as $item) {
                if ($item['count'] < 3) {
                    $coldNumbers[] = intval($item['number']);
                }
            }
        }
        // =========================================================================

        $row = DB::table('ssq_lotto_history')->select('red_ball_omission')->orderByDesc('id')->first();
        if (!$row) return response()->json(['code' => 500, 'msg' => '历史数据不存在']);
        $redOmit = json_decode($row->red_ball_omission, true);

        $try = 0;
        while ($try < 100) {
            $try++;
            
            // 1. 胆码生成逻辑（保持原状：保护前端传来的胆码组合，哪怕它是冷号）
            if (!empty($preferDans)) {
                $baseDans = array_map('intval', $preferDans);
                if (count($baseDans) < $danCount) {
                    $extraPool = array_diff(range(1, 33), $baseDans);
                    $extraDans = collect($extraPool)->shuffle()->take($danCount - count($baseDans))->toArray();
                    $danNums = array_merge($baseDans, $extraDans);
                } else {
                    $danNums = array_slice($baseDans, 0, $danCount);
                }
            } else {
                // 如果用户没传胆码，系统随机生成胆码时，也主动避开冷号，保证纯净度
                $safeDanPool = array_diff(range(1, 33), $coldNumbers);
                // 如果冷号太多导致安全池不够机选胆码（极端情况），退回全集
                if (count($safeDanPool) < $danCount) { $safeDanPool = range(1, 33); }
                
                $danNums = collect($safeDanPool)->shuffle()->take($danCount)->toArray();
            }
            sort($danNums);

            // =================【核心修改：拖码池完全切除冷号】=================
            // 从 1-33 中，同时把“已被选的胆码”和“系统冷号”连根拔除
            $pureTuoPool = array_values(array_diff(range(1, 33), $danNums, $coldNumbers));
            
            // 兜底防护：如果冷号杀得太狠，导致剩下的常规号不够凑齐用户要的拖码数
            if (count($pureTuoPool) < $tuoCount) {
                // 退一步：只排除胆码，允许冷号进入，确保程序不崩（死循环）
                $pureTuoPool = array_values(array_diff(range(1, 33), $danNums));
            }

            // 纯净池随机摇出拖码
            $tuoNums = collect($pureTuoPool)->shuffle()->take($tuoCount)->sort()->values()->toArray();
            // ====================================================================

            // 2. 综合校验（胆码 + 拖码组合成最终红球）
            $reds = array_merge($danNums, $tuoNums);
            sort($reds);
            
            $redOmits = array_map(fn($n) => $redOmit[$n] ?? 0, $reds);

            // 过滤条件：最大遗漏 < 6 或 最小遗漏 > 1 或 跨度 < 11 则重试
            if (max($redOmits) < 6 || min($redOmits) > 1 || (max($reds) - min($reds)) < 11) {
                continue;
            }

            $blues = collect(range(1, 16))->shuffle()->take($blueCount)->sort()->values()->toArray();

            // 3. 存入统一记录表
            DB::table('user_lotto_records')->insert([
                'user_id'      => $user->id,
                'lottery_type' => 'ssq',
                'is_fushi'     => 1,
                'issue'        => $request->input('issue'),
                'mode'         => 'dantuo_fushi',
                'red_dan'      => implode(',', $danNums),
                'kill_numbers' => implode(',', $coldNumbers), // 记录下被动态干掉的杀号库
                'red_numbers'  => implode(',', $tuoNums), // 拖码
                'blue_numbers' => implode(',', $blues),
                'ip'           => $request->ip(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            return response()->json([
                'code' => 200,
                'data' => [
                    'dan'  => $danNums, 
                    'tuo'  => $tuoNums, 
                    'blue' => $blues
                ]
            ]);
        }

        return response()->json(['code' => 500, 'msg' => '生成失败，请重试']);
    }


    /**
     * 3. 固定杀号复式
     */
    public function fixedKillFushi(Request $request)
    {
        $user = $request->user();
        if (!$this->checkRateLimit($user)) {
            return response()->json(['code' => 429, 'msg' => '操作太频繁'], 429);
        }

        $redCount = intval($request->input('red_count', 6));
        $blueCount = intval($request->input('blue_count', 1));
        $killNumbers = $request->input('kill_numbers', '');

        $row = DB::table('ssq_lotto_history')->select('red_ball_omission')->orderByDesc('id')->first();
        if (!$row) return response()->json(['code' => 500, 'msg' => '数据不存在']);
        
        $redOmit = json_decode($row->red_ball_omission, true);
        $killArr = array_map('intval', array_filter(explode(',', $killNumbers)));

        $try = 0;
        while ($try < 100) {
            $try++;
            $pool = array_diff(range(1, 33), $killArr);
            $tuoNums = collect($pool)->shuffle()->take($redCount)->sort()->values()->toArray();
            $redOmits = array_map(fn($n) => $redOmit[$n] ?? 0, $tuoNums);

            if (max($redOmits) < 6 || min($redOmits) > 1) continue;

            $blues = collect(range(1, 16))->shuffle()->take($blueCount)->sort()->values()->toArray();

            DB::table('user_lotto_records')->insert([
                'user_id'      => $user->id,
                'lottery_type' => 'ssq', // <--- 修复点
                'is_fushi'     => 1,
                'issue'        => $request->input('issue'),
                'mode'         => 'guding_kill_fushi',
                'red_dan'      => '',
                'kill_numbers' => implode(',', $killArr),
                'red_numbers'  => implode(',', $tuoNums),
                'blue_numbers' => implode(',', $blues),
                'ip'           => $request->ip(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            return response()->json(['code' => 200, 'data' => ['red' => $tuoNums, 'blue' => $blues]]);
        }

        return response()->json(['code' => 500, 'msg' => '生成失败']);
    }

    /**
     * 4. 用户自定义杀号复式
     */
    public function userKillFushi(Request $request)
    {
        $user = $request->user();
        if (!$this->checkRateLimit($user)) {
            return response()->json(['code' => 429, 'msg' => '操作太频繁'], 429);
        }

        $redCount = intval($request->input('red_count', 6));
        $blueCount = intval($request->input('blue_count', 1));
        $killNumbers = $request->input('kill_numbers', '');

        $row = DB::table('ssq_lotto_history')->select('red_ball_omission')->orderByDesc('id')->first();
        $redOmit = json_decode($row->red_ball_omission, true);
        $killArr = array_map('intval', array_filter(explode(',', $killNumbers)));

        $try = 0;
        while ($try < 100) {
            $try++;
            $pool = array_diff(range(1, 33), $killArr);
            $tuoNums = collect($pool)->shuffle()->take($redCount)->sort()->values()->toArray();
            $redOmits = array_map(fn($n) => $redOmit[$n] ?? 0, $tuoNums);

            if (max($redOmits) < 6 || min($redOmits) > 1) continue;

            $blues = collect(range(1, 16))->shuffle()->take($blueCount)->sort()->values()->toArray();

            DB::table('user_lotto_records')->insert([
                'user_id'      => $user->id,
                'lottery_type' => 'ssq', // <--- 修复点
                'is_fushi'     => 1,
                'issue'        => $request->input('issue'),
                'mode'         => 'diy_kill_fushi',
                'red_dan'      => '',
                'kill_numbers' => implode(',', $killArr),
                'red_numbers'  => implode(',', $tuoNums),
                'blue_numbers' => implode(',', $blues),
                'ip'           => $request->ip(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            return response()->json(['code' => 200, 'data' => ['tuo' => $tuoNums, 'blue' => $blues]]);
        }

        return response()->json(['code' => 500, 'msg' => '生成失败']);
    }

    private function hasPrime($nums)
    {
        $primes = [2, 3, 5, 7, 11, 13, 17, 19, 23, 29, 31];
        foreach ($nums as $n) {
            if (in_array($n, $primes)) return true;
        }
        return false;
    }
}