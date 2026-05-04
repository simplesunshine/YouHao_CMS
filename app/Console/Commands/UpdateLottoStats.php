<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateLottoStats extends Command
{
    protected $signature = 'lotto:update-stats';
    protected $description = '更新和值、跨度及上期重复号码等连续性指标';

    public function handle()
    {
        $this->info(">>> 任务开始：正在按 ID 顺序处理趋势连续性数据...");

        // 必须按 ID 升序，确保“上期”逻辑正确
        $records = DB::table('ssq_lotto_history')->orderBy('id', 'asc')->get();

        if ($records->isEmpty()) {
            $this->error("数据库中没有数据。");
            return;
        }

        // 初始化追踪器
        $last = [
            'zone_ratio' => null,
            'odd_count'  => null,
            'big_count'  => null,
            'sum_tail'   => null,
            'sum_range'  => null,
            'span'       => null,
            'reds'       => [], // 新增：用于存储上一期的红球数组
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
                // 当前期红球
                $currentReds = [
                    (int)$row->front1, (int)$row->front2, (int)$row->front3, 
                    (int)$row->front4, (int)$row->front5, (int)$row->front6
                ];

                // --- 1. 基础形态计算 ---
                $currentSpan = max($currentReds) - min($currentReds);
                $currentSum = array_sum($currentReds);
                $currentSumTail = $currentSum % 10;
                $currentSumRange = floor($currentSum / 10);

                // 计算重复号码 (核心新增)
                $duplicateNums = [];
                $duplicateCount = 0;
                if (!empty($last['reds'])) {
                    // 取交集，得出重复的号码
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
                        'duplicate_count'        => $duplicateCount, // 新增：重复个数
                        'duplicate_nums'         => implode(',', $duplicateNums), // 新增：重复的号码字符串
                        'continuous_zone_count'  => $counts['zone'],
                        'continuous_odd_count'   => $counts['odd'],
                        'continuous_big_count'   => $counts['big'],
                        'continuous_sum_tail'    => $counts['sum_tail'],
                        'continuous_sum_range'   => $counts['sum_range'],
                        'continuous_span_count'  => $counts['span'],
                        'updated_at'             => now(),
                    ]);

                // --- 4. 存入当前值供下一轮对比 ---
                $last['zone_ratio'] = $currentZoneRatio;
                $last['odd_count']  = $currentOdd;
                $last['big_count']  = $currentBig;
                $last['sum_tail']   = $currentSumTail;
                $last['sum_range']  = $currentSumRange;
                $last['span']       = $currentSpan;
                $last['reds']       = $currentReds; // 核心：存入当前红球，下一圈就是“上期”了

                if ($index % 500 == 0 && $index > 0) {
                    $this->line("已处理 {$index} 条数据...");
                }
            }
            DB::commit();
            $this->info(">>> 任务成功！所有指标（含重复号码）已更新完毕。");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("执行失败：" . $e->getMessage());
        }
    }
}