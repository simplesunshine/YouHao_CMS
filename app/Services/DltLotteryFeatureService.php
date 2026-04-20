<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DltLotteryFeatureService
{
    /**
     * 上期特征（统一入口）
     */
    public static function lastIssue()
    {
        return Cache::remember('dlt_last_issue_features', 60, function () {

            $last = DB::table('dlt_lotto_history')
                ->orderByDesc('id')
                ->first();

            if (!$last) return null;

            return [
                'span'       => $last->span,
                'front_sum'  => $last->front_sum,
                'zone_ratio' => explode(',', $last->zone_ratio),

                // ⭐ 冷号统一来源
                'cold_numbers' => json_decode($last->next_red_max_miss_json ?? '[]', true) ?? []
            ];
        });
    }

    /**
     * 位置统计（统一缓存）
     */
    public static function positionCounts($limit = 80)
    {
        return Cache::remember('dlt_last50_pos_counts', 60, function () use ($limit) {

            $rows = DB::table('dlt_lotto_history')
                ->orderByDesc('id')
                ->limit($limit)
                ->select(['front1','front2','front3','front4','front5'])
                ->get();

            $counts = [];

            for ($i = 1; $i <= 5; $i++) {
                $counts[$i] = [];
            }

            foreach ($rows as $row) {
                for ($i = 1; $i <= 5; $i++) {

                    $num = $row->{'front'.$i};

                    $counts[$i][$num] = ($counts[$i][$num] ?? 0) + 1;
                }
            }

            return $counts;
        });
    }

    /**
     * 单注特征计算（核心统一逻辑）
     */
    public static function buildFeatures($row, $last, $posCounts)
    {
        $reds = array_map('intval', explode(',', $row->front_numbers));

        // 冷号命中
        $coldHit = array_values(array_intersect($reds, $last['cold_numbers']));

        // 区间
        $zoneSame =
            isset($last['zone_ratio'][0], $last['zone_ratio'][1], $last['zone_ratio'][2]) &&
            $row->zone1_count == $last['zone_ratio'][0] &&
            $row->zone2_count == $last['zone_ratio'][1] &&
            $row->zone3_count == $last['zone_ratio'][2];

        // 位置统计
        $posAppear = [];
        $lowPosNums = [];

        for ($i = 1; $i <= 5; $i++) {

            $num = $row->{'front_'.$i};

            $count = $posCounts[$i][$num] ?? 0;

            $posAppear[] = $count;

            if ($count === 0) {
                $lowPosNums[] = $num;
            }
        }

        return [
            'span_same'      => $row->span == $last['span'],
            'sum_same'       => $row->front_sum == $last['front_sum'],
            'zone_same'      => $zoneSame,
            'cold_numbers'   => $coldHit,
            'continue_count' => $row->consecutive_count,
            'pos_appear'     => $posAppear,
            'low_pos_nums'   => $lowPosNums
        ];
    }

    public static function buildRow($row)
    {
        $last = self::lastIssue();

        if (!$last) return null;

        $pos = self::positionCounts();

        return [
            'id' => $row->id,
            'front_numbers' => $row->front_numbers,
            'back_numbers' => $row->back_numbers,

            'features' => self::buildFeatures($row, $last, $pos)
        ];
    }
}