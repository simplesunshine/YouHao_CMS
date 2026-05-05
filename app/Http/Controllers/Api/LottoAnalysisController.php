<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class LottoAnalysisController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->input('type', 'ssq');
        $frontNumbers = $request->input('front_numbers');

        // 1. 预处理：规范化用户输入
        $nums = preg_split('/[,\s\-]+/', $frontNumbers);
        $nums = array_filter(array_map('intval', $nums));
        sort($nums);
        $frontStr = implode(',', $nums);

        $isSsq = ($type === 'ssq');
        $needCount = $isSsq ? 6 : 5;

        if (count($nums) !== $needCount) {
            return response()->json(['success' => false, 'message' => "号码数量不正确"], 400);
        }

        $historyTable = $isSsq ? 'ssq_lotto_history' : 'dlt_lotto_history';

        // 2. 查询机选演算库
        $recTable = $isSsq ? 'basic_ssq' : 'basic_dlt';
        $recRecord = DB::table($recTable)->where('front', $frontStr)->first();
        $exists = !is_null($recRecord);

        // 3. 实时特征
        $oddCount = count(array_filter($nums, fn($n) => $n % 2 !== 0));
        $posAppear = $this->calculatePositionFrequency($historyTable, $nums, $needCount);

        $features = [
            'weight'     => $exists ? ($recRecord->weight ?? 0) : 0,
            'odd_even'   => $oddCount . ':' . ($needCount - $oddCount),
            'sum'        => array_sum($nums),
            'zone_ratio' => $this->calculateZoneRatio($nums, $type),
            'span'       => (count($nums) > 0) ? (max($nums) - min($nums)) : 0,
            'pos_appear' => $posAppear,
        ];

        // 4. 历史碰撞：传入 $isSsq 判定不同门槛
        $historyCollisions = $this->getHistoryCollisions($historyTable, $nums, $needCount, $isSsq);

        return response()->json([
            'exists'   => $exists,
            'features' => $features,
            'history'  => $historyCollisions,
            'message'  => $exists ? '匹配成功' : '该号码未在演算库中'
        ]);
    }

    private function calculatePositionFrequency($table, $userNums, $count)
    {
        $fields = [];
        for ($i = 1; $i <= $count; $i++) { $fields[] = 'front' . $i; }

        $recentHistories = DB::table($table)->select($fields)->orderBy('id', 'desc')->limit(80)->get();
        $frequency = array_fill(0, $count, 0);

        foreach ($recentHistories as $h) {
            for ($i = 0; $i < $count; $i++) {
                $f = 'front' . ($i + 1);
                if (isset($h->$f) && (int)$h->$f === $userNums[$i]) {
                    $frequency[$i]++;
                }
            }
        }
        return $frequency;
    }

    private function calculateZoneRatio($nums, $type)
    {
        $z1 = $z2 = $z3 = 0;
        foreach ($nums as $n) {
            if ($type === 'ssq') {
                if ($n <= 11) $z1++; elseif ($n <= 22) $z2++; else $z3++;
            } else {
                if ($n <= 12) $z1++; elseif ($n <= 24) $z2++; else $z3++;
            }
        }
        return "$z1:$z2:$z3";
    }

    /**
     * 历史碰撞：双色球 5+，大乐透 4+
     */
    private function getHistoryCollisions($table, $userNums, $maxFront, $isSsq)
    {
        // 1. 设置门槛
        $minHit = $isSsq ? 5 : 4;

        // 2. 全量取出，按 ID 倒序
        $histories = DB::table($table)->orderBy('id', 'desc')->get();
        $results = [];

        foreach ($histories as $h) {
            // 直接从 front1-front6 字段构造数组比对，最稳
            $hNums = [];
            for ($i = 1; $i <= $maxFront; $i++) {
                $f = "front" . $i;
                if (isset($h->$f)) {
                    $hNums[] = (int)$h->$f;
                }
            }
            
            // 如果分字段没拿到数据，退而求其次解析字符串
            if (empty($hNums) && isset($h->front)) {
                $hNums = array_filter(array_map('intval', preg_split('/[,\s]+/', trim($h->front))));
            }

            // 计算交集
            $hitCount = count(array_intersect($userNums, $hNums));

            // 3. 应用动态门槛
            if ($hitCount >= $minHit) {
                $showNum = $h->front ?? implode(',', $hNums);
                $showBack = '';
                if (isset($h->back_numbers)) {
                    $showBack = ' 后区:'.$h->back_numbers;
                } elseif (isset($h->back1)) {
                    $showBack = ' 后区:'.$h->back1 . (isset($h->back2) ? ','.$h->back2 : '');
                }

                $results[] = [
                    'id'        => (int)$h->id,
                    'issue'     => (string)$h->issue,
                    'hit_count' => $hitCount,
                    'numbers'   => '前区:'.$showNum . $showBack
                ];
            }
        }

        // 4. 排序：命中数第一，ID（时间）第二
        usort($results, function($a, $b) {
            if ($a['hit_count'] !== $b['hit_count']) {
                return $b['hit_count'] <=> $a['hit_count'];
            }
            return $b['id'] <=> $a['id'];
        });

        return array_slice($results, 0, 1000);
    }

    private function logQuery($request, $type, $frontStr, $exists)
    {
        try {
            DB::table('user_lotto_queries')->insert([
                'lotto_type'    => $type === 'ssq' ? 1 : 2,
                'front'         => $frontStr,
                'ip'            => $request->ip(),
                'hit_library'   => $exists ? 1 : 0,
                'created_at'    => now(),
            ]);
        } catch (\Exception $e) { }
    }
}