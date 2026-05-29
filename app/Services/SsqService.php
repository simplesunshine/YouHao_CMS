<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SsqService
{
    /**
     * 获取双色球号码分布趋势（冷热度分析核心逻辑）
     *
     * @param int $limit 查询期数
     * @return array|null 成功返回统计数据，无数据返回 null
     */
    public function getNumberDistribution(int $limit = 30): ?array
    {
        // 1. 获取最近的开奖数据
        $history = DB::table('ssq_lotto_history')
            ->orderBy('issue', 'desc')
            ->limit($limit)
            ->get();

        if ($history->isEmpty()) {
            return null;
        }

        // 2. 初始化红球(1-33)和蓝球(1-16)的计数器
        $redStats = array_fill(1, 33, 0);
        $blueStats = array_fill(1, 16, 0);

        // 辅助解析函数
        $parseNumbers = function($str) {
            if (!$str) return [];
            $parts = preg_split('/[,\s]+/', trim($str));
            return array_filter(array_map('intval', $parts));
        };

        // 3. 遍历历史数据进行统计
        foreach ($history as $row) {
            $reds = $parseNumbers($row->front);
            $blues = $parseNumbers($row->back);

            foreach ($reds as $num) {
                if (isset($redStats[$num])) $redStats[$num]++;
            }
            foreach ($blues as $num) {
                if (isset($blueStats[$num])) $blueStats[$num]++;
            }
        }

        // 4. 格式化数据
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