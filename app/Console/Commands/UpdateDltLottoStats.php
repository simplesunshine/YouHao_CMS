<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateDltLottoStats extends Command
{
    protected $signature = 'lotto:update-dlt-stats';
    protected $description = '补全大乐透历史表的趋势连续性及重复号码数据';

    public function handle()
    {
        $this->info(">>> 任务开始：正在处理大乐透历史趋势数据...");

        // 严格按 ID 升序，确保逻辑闭环
        $records = DB::table('dlt_lotto_history')->orderBy('id', 'asc')->get();

        if ($records->isEmpty()) {
            $this->error("未发现历史数据。");
            return;
        }

        // 初始化追踪器（用于对比上一期）
        $last = [
            'zone_ratio' => null,
            'odd_count'  => null,
            'big_count'  => null,
            'sum_tail'   => null,
            'sum_range'  => null,
            'span'       => null,
            'reds'       => [], 
        ];

        $counts = [
            'zone'      => 1,
            'odd'       => 1,
            'big'       => 1,
            'sum_tail'  => 1,
            'sum_range' => 1,
            'span'      => 1,
        ];

        DB::beginTransaction();
        try {
            foreach ($records as $index => $row) {
                // 1. 获取当前前区号码（按 DDL 逻辑提取 6 个字段）
                $currentReds = [
                    (int)$row->front1, (int)$row->front2, (int)$row->front3, 
                    (int)$row->front4, (int)$row->front5
                ];
                // 过滤掉可能的 0（如果某期只有 5 个号）
                $currentReds = array_filter($currentReds);
                sort($currentReds);

                // --- 2. 基础形态计算 ---
                $currentSpan = !empty($currentReds) ? (max($currentReds) - min($currentReds)) : 0;
                $currentSum = array_sum($currentReds);
                $currentSumTail = $currentSum % 10;
                $currentSumRange = floor($currentSum / 10);

                // 计算奇数个数
                $currentOdd = 0;
                foreach ($currentReds as $n) { if ($n % 2 !== 0) $currentOdd++; }

                // 计算大小比（大乐透 1-35，通常 18 及以上为大）
                $currentBig = 0;
                foreach ($currentReds as $n) { if ($n >= 18) $currentBig++; }

                // 计算三区比（大乐透标准划分：1-12, 13-24, 25-35）
                $z1 = 0; $z2 = 0; $z3 = 0;
                foreach ($currentReds as $n) {
                    if ($n <= 12) $z1++; 
                    elseif ($n <= 24) $z2++; 
                    else $z3++;
                }
                $currentZoneRatio = "{$z1}:{$z2}:{$z3}";

                // --- 3. 重复号码逻辑 ---
                $duplicateNums = [];
                $duplicateCount = 0;
                if (!empty($last['reds'])) {
                    $duplicateNums = array_intersect($currentReds, $last['reds']);
                    $duplicateCount = count($duplicateNums);
                }

                // --- 4. 连续性逻辑处理 ---
                $counts['span']      = ($last['span'] !== null && $currentSpan === $last['span']) ? $counts['span'] + 1 : 1;
                $counts['zone']      = ($last['zone_ratio'] !== null && $currentZoneRatio === $last['zone_ratio']) ? $counts['zone'] + 1 : 1;
                $counts['odd']       = ($last['odd_count'] !== null && $currentOdd === $last['odd_count']) ? $counts['odd'] + 1 : 1;
                $counts['big']       = ($last['big_count'] !== null && $currentBig === $last['big_count']) ? $counts['big'] + 1 : 1;
                $counts['sum_tail']  = ($last['sum_tail'] !== null && $currentSumTail === $last['sum_tail']) ? $counts['sum_tail'] + 1 : 1;
                $counts['sum_range'] = ($last['sum_range'] !== null && $currentSumRange === $last['sum_range']) ? $counts['sum_range'] + 1 : 1;

                // --- 5. 更新数据库 ---
                DB::table('dlt_lotto_history')
                    ->where('id', $row->id)
                    ->update([
                        'sum'                    => (string)$currentSum,
                        'sum'                    => (string)$currentSum,
                        'span'                   => $currentSpan,
                        'odd_count'              => $currentOdd,
                        'even_count'             => count($currentReds) - $currentOdd,
                        'zone_ratio'             => $currentZoneRatio,
                        'duplicate_count'        => $duplicateCount,
                        'duplicate_nums'         => implode(',', $duplicateNums),
                        'continuous_zone_count'  => $counts['zone'],
                        'continuous_odd_count'   => $counts['odd'],
                        'continuous_big_count'   => $counts['big'],
                        'continuous_sum_tail'    => (int)$counts['sum_tail'],
                        'continuous_sum_range'   => (int)$counts['sum_range'],
                        'continuous_span_count'  => (int)$counts['span'],
                        'updated_at'             => now(),
                    ]);

                // --- 6. 状态迭代 ---
                $last['zone_ratio'] = $currentZoneRatio;
                $last['odd_count']  = $currentOdd;
                $last['big_count']  = $currentBig;
                $last['sum_tail']   = $currentSumTail;
                $last['sum_range']  = $currentSumRange;
                $last['span']       = $currentSpan;
                $last['reds']       = $currentReds;

                if ($index % 500 == 0 && $index > 0) {
                    $this->line("已完成 {$index} 条...");
                }
            }
            DB::commit();
            $this->info(">>> 任务成功！大乐透历史统计指标已全部补全。");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("执行出错：" . $e->getMessage());
        }
    }
}