<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateLottoStats extends Command
{
    protected $signature = 'lotto:update-stats';
    protected $description = '更新和值、跨度等连续出现指标';

    public function handle()
    {
        $this->info(">>> 任务开始：正在按 ID 顺序处理趋势连续性数据...");

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
            'span'       => null, // 新增：跨度追踪
        ];

        $counts = [
            'zone'      => 1,
            'odd'       => 1,
            'big'       => 1,
            'sum_tail'  => 1,
            'sum_range' => 1,
            'span'      => 1, // 新增：跨度计数器
        ];

        DB::beginTransaction();
        try {
            foreach ($records as $index => $row) {
                $reds = [
                    (int)$row->front1, (int)$row->front2, (int)$row->front3, 
                    (int)$row->front4, (int)$row->front5, (int)$row->front6
                ];

                // --- 1. 基础形态计算 ---
                $currentSpan = max($reds) - min($reds); // 计算跨度
                $currentSum = array_sum($reds);
                $currentSumTail = $currentSum % 10;
                $currentSumRange = floor($currentSum / 10);

                $currentOdd = 0;
                foreach ($reds as $n) { if ($n % 2 !== 0) $currentOdd++; }

                $currentBig = 0;
                foreach ($reds as $n) { if ($n >= 17) $currentBig++; }

                $z1 = 0; $z2 = 0; $z3 = 0;
                foreach ($reds as $n) {
                    if ($n <= 11) $z1++; elseif ($n <= 22) $z2++; else $z3++;
                }
                $currentZoneRatio = "{$z1}:{$z2}:{$z3}";

                // --- 2. 连续性逻辑处理 ---

                // 跨度连续 (新增)
                if ($last['span'] !== null && $currentSpan === $last['span']) {
                    $counts['span']++;
                } else {
                    $counts['span'] = 1;
                }

                // 其他连续逻辑保持不变
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
                        'continuous_zone_count'  => $counts['zone'],
                        'continuous_odd_count'   => $counts['odd'],
                        'continuous_big_count'   => $counts['big'],
                        'continuous_sum_tail'    => $counts['sum_tail'],
                        'continuous_sum_range'   => $counts['sum_range'],
                        'continuous_span_count'  => $counts['span'], // 对应新增跨度字段
                        'updated_at'             => now(),
                    ]);

                // 4. 存入当前值供下一轮对比
                $last['zone_ratio'] = $currentZoneRatio;
                $last['odd_count']  = $currentOdd;
                $last['big_count']  = $currentBig;
                $last['sum_tail']   = $currentSumTail;
                $last['sum_range']  = $currentSumRange;
                $last['span']       = $currentSpan;

                if ($index % 500 == 0 && $index > 0) {
                    $this->line("已处理 {$index} 条数据...");
                }
            }
            DB::commit();
            $this->info(">>> 任务成功！所有指标已按 ID 顺序更新完毕。");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("执行失败：" . $e->getMessage());
        }
    }
}