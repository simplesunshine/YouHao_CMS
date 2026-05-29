<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DltService
{
    /**
     * 获取大乐透号码分布趋势（冷热度分析核心逻辑）
     *
     * @param int $limit 查询期数
     * @return array|null 成功返回统计数据，无数据返回 null
     */
    public function getNumberDistribution(int $limit = 40): ?array
    {
        // 1. 从历史开奖表提取最近的 N 期数据
        $history = DB::table('dlt_lotto_history')
            ->orderBy('issue', 'desc')
            ->limit($limit)
            ->get();

        if ($history->isEmpty()) {
            return null;
        }

        // 2. 初始化大乐透计数器：前区（1-35），后区（1-12）
        $redStats  = array_fill(1, 35, 0);
        $blueStats = array_fill(1, 12, 0);

        // 3. 遍历历史数据进行高频频次统计
        foreach ($history as $row) {
            $reds  = [];
            $blues = [];

            if (isset($row->front)) {
                // 如果是存储的逗号分隔字符串 "01,12,23..."
                $reds  = array_filter(array_map('intval', explode(',', $row->front)));
                $blues = array_filter(array_map('intval', explode(',', $row->back)));
            } else {
                // 如果是独立字段形态（front1 ~ front5, back1 ~ back2）
                $reds = [
                    (int)($row->front1 ?? 0), (int)($row->front2 ?? 0), (int)($row->front3 ?? 0), 
                    (int)($row->front4 ?? 0), (int)($row->front5 ?? 0)
                ];
                $blues = [
                    (int)($row->back1 ?? 0), (int)($row->back2 ?? 0)
                ];
            }

            // 累加频次
            foreach ($reds as $num) {
                if ($num >= 1 && $num <= 35) {
                    $redStats[$num]++;
                }
            }
            foreach ($blues as $num) {
                if ($num >= 1 && $num <= 12) {
                    $blueStats[$num]++;
                }
            }
        }

        // 4. 辅助格式化函数
        $formatData = function($stats) {
            $result = [];
            foreach ($stats as $num => $count) {
                $result[] = [
                    'number' => str_pad($num, 2, '0', STR_PAD_LEFT),
                    'count'  => $count
                ];
            }
            return $result;
        };

        return [
            'red'        => $formatData($redStats),
            'blue'       => $formatData($blueStats),
            'limit'      => $limit,
            'last_issue' => $history->first()->issue
        ];
    }
}