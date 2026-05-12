<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateLottoStats extends Command
{
    protected $signature = 'lotto:update-stats';
    protected $description = '更新和值、跨度、重复号码及相同和值间隔期数';

    public function handle()
    {
        $this->info(">>> 任务开始：正在按 ID 顺序处理数据...");

        $records = DB::table('ssq_lotto_history')->orderBy('id', 'asc')->get();

        if ($records->isEmpty()) {
            $this->error("数据库中没有数据。");
            return;
        }

        // 初始化追踪器
        $last = [
            'zone_ratio' => null, 'odd_count' => null, 'big_count' => null,
            'sum_tail' => null, 'sum_range' => null, 'span' => null, 'reds' => [],
        ];

        // 用于记录每个“和值”上一次出现的索引位置（Index）
        // Key 为和值，Value 为该和值在上一次循环中的 $index
        $sumLastSeenIndex = [];

        $counts = [
            'zone' => 1, 'odd' => 1, 'big' => 1, 'sum_tail' => 1, 'sum_range' => 1, 'span' => 1,
        ];

        DB::beginTransaction();
        try {
            foreach ($records as $index => $row) {
                $currentReds = [
                    (int)$row->front1, (int)$row->front2, (int)$row->front3, 
                    (int)$row->front4, (int)$row->front5, (int)$row->front6
                ];

                // --- 1. 基础形态计算 ---
                $currentSpan = max($currentReds) - min($currentReds);
                $currentSum = array_sum($currentReds);
                $currentSumTail = $currentSum % 10;
                $currentSumRange = floor($currentSum / 10);

                // 计算相同和值间隔
                // 逻辑：当前索引 - 上次出现该和值的索引 = 间隔期数
                $sumInterval = 0;
                if (isset($sumLastSeenIndex[$currentSum])) {
                    $sumInterval = $index - $sumLastSeenIndex[$currentSum];
                }
                // 更新该和值最后一次出现的索引位置
                $sumLastSeenIndex[$currentSum] = $index;

                // 计算重复号码
                $duplicateNums = [];
                $duplicateCount = 0;
                if (!empty($last['reds'])) {
                    $duplicateNums = array_intersect($currentReds, $last['reds']);
                    $duplicateCount = count($duplicateNums);
                }

                $currentOdd = 0;
                foreach ($currentReds as $n) { if ($n % 2 !== 0) $currentOdd++; }

                $currentBig = 0;
                foreach ($currentReds as $n) { if ($n >= 17) $currentBig++; }

                $z1 = 0; $z2 = 0; $z3 = 0;
                foreach ($currentReds as $n) {
                    if ($n <= 11) $z1++; elseif ($n <= 22) $z2++; else $z3++;
                }
                $currentZoneRatio = "{$z1}:{$z2}:{$z3}";

                // --- 2. 连续性逻辑处理 ---
                $counts['span'] = ($last['span'] !== null && $currentSpan === $last['span']) ? $counts['span'] + 1 : 1;
                $counts['zone'] = ($last['zone_ratio'] !== null && $currentZoneRatio === $last['zone_ratio']) ? $counts['zone'] + 1 : 1;
                $counts['odd']  = ($last['odd_count'] !== null && $currentOdd === $last['odd_count']) ? $counts['odd'] + 1 : 1;
                $counts['big']  = ($last['big_count'] !== null && $currentBig === $last['big_count']) ? $counts['big'] + 1 : 1;
                $counts['sum_tail'] = ($last['sum_tail'] !== null && $currentSumTail === $last['sum_tail']) ? $counts['sum_tail'] + 1 : 1;
                $counts['sum_range'] = ($last['sum_range'] !== null && $currentSumRange === $last['sum_range']) ? $counts['sum_range'] + 1 : 1;

                // --- 3. 更新数据库 ---
                DB::table('ssq_lotto_history')
                    ->where('id', $row->id)
                    ->update([
                        'span'                   => $currentSpan,
                        'odd_count'              => $currentOdd,
                        'even_count'             => 6 - $currentOdd,
                        'zone_ratio'             => $currentZoneRatio,
                        'sum'                    => $currentSum,
                        'sum_interval'           => $sumInterval, // 新增：间隔期数
                        'duplicate_count'        => $duplicateCount,
                        'duplicate_nums'         => implode(',', $duplicateNums),
                        'continuous_zone_count'  => $counts['zone'],
                        'continuous_odd_count'   => $counts['odd'],
                        'continuous_big_count'   => $counts['big'],
                        'continuous_sum_tail'    => $counts['sum_tail'],
                        'continuous_sum_range'   => $counts['sum_range'],
                        'continuous_span_count'  => $counts['span'],
                        'updated_at'             => now(),
                    ]);

                // --- 4. 存入当前值供下一轮对比 ---
                $last = [
                    'zone_ratio' => $currentZoneRatio,
                    'odd_count'  => $currentOdd,
                    'big_count'  => $currentBig,
                    'sum_tail'   => $currentSumTail,
                    'sum_range'  => $currentSumRange,
                    'span'       => $currentSpan,
                    'reds'       => $currentReds,
                ];

                if ($index % 500 == 0 && $index > 0) {
                    $this->line("已处理 {$index} 条数据...");
                }
            }
            DB::commit();
            $this->info(">>> 任务成功！所有指标（含相同和值间隔）已更新完毕。");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("执行失败：" . $e->getMessage());
        }
    }
}