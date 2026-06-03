<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * 获取看板数据
     */
    public function index($type)
    {
        $tableName = $this->getTableName($type);
        if (!$tableName) {
            return response()->json(['success' => false, 'message' => '非法类型']);
        }

        // 定义缓存键名，区分 ssq 和 dlt
        $cacheKey = "api_dashboard_data_{$type}";

        // 缓存 1800 秒 (30 分钟)
        return Cache::remember($cacheKey, 1800, function () use ($type, $tableName) {
            
            // 1. 获取最新一期数据
            $lastRecord = DB::table($tableName)->orderBy('id', 'desc')->first();
            if (!$lastRecord) {
                return response()->json(['success' => false, 'message' => '暂无历史数据']);
            }

            $currentReds = $this->extractReds($lastRecord, $type);
            $currentBlues = $this->extractBlues($lastRecord, $type);

            // 2. 连号预警统计 (最近10期)
            $recentHistory = DB::table($tableName)
                ->where('id', '<=', $lastRecord->id)
                ->orderBy('id', 'desc')
                ->limit(10)
                ->get();

            $continuousHits = [];
            foreach ($currentReds as $num) {
                $count = 0;
                foreach ($recentHistory as $record) {
                    if (in_array($num, $this->extractReds($record, $type))) {
                        $count++;
                    } else {
                        break; 
                    }
                }
                $continuousHits[$num] = $count;
            }

            // 3. 解析下期最大遗漏值及其期数
            $maxOmissionNum = '无';
            $maxOmissionCount = 0;
            if (!empty($lastRecord->next_red_max_miss_json)) {
                $missNums = is_array($lastRecord->next_red_max_miss_json) 
                    ? $lastRecord->next_red_max_miss_json 
                    : json_decode($lastRecord->next_red_max_miss_json, true);

                $omissionMap = is_array($lastRecord->red_ball_omission)
                    ? $lastRecord->red_ball_omission
                    : json_decode($lastRecord->red_ball_omission, true);

                if (is_array($missNums) && count($missNums) > 0 && is_array($omissionMap)) {
                    $formattedNums = [];
                    $counts = [];
                    foreach ($missNums as $num) {
                        $key = (int)$num; 
                        $formattedNums[] = str_pad($num, 2, '0', STR_PAD_LEFT);
                        if (isset($omissionMap[$key])) $counts[] = $omissionMap[$key];
                    }
                    $maxOmissionNum = implode(',', $formattedNums);
                    $maxOmissionCount = count($counts) > 0 ? max($counts) : 0;
                }
            }

            // 4. 历史重现追溯
            $historyQuery = DB::table($tableName)
                ->where('id', '<', $lastRecord->id)
                ->where('front1', $lastRecord->front1)
                ->where('front2', $lastRecord->front2)
                ->where('front3', $lastRecord->front3)
                ->where('front4', $lastRecord->front4)
                ->where('front5', $lastRecord->front5);

            if ($type === 'ssq') {
                $historyQuery->where('front6', $lastRecord->front6);
            }

            $totalHit = $historyQuery->count();
            $lastTimeRecord = $historyQuery->orderBy('id', 'desc')->first();

            // 5. 获取近期高频组合 (注意：这里内部也有缓存，但包裹在外层后，主要由外层控制)
            $hotCombos = $this->getHotComboStats($type, $tableName);

            // 返回构建好的数据数组（Cache::remember 会自动处理 response 对象，但存数组更稳妥）
            return [
                'success' => true,
                'data' => [
                    'last_issue' => $lastRecord->issue,
                    'last_numbers' => $currentReds,
                    'last_blues' => $currentBlues,
                    'continuous_hits' => $continuousHits,
                    'max_omission_num' => $maxOmissionNum,
                    'max_omission_count' => $maxOmissionCount,
                    'history_total_hit' => $totalHit,
                    //'last_time' => $lastTimeRecord ? $lastTimeRecord->open_date : null,
                    'hot_combos' => $hotCombos
                ]
            ];
        });
    }

    /**
     * 获取高频二连组合逻辑（区分彩种频次过滤）
     */
    private function getHotComboStats($type, $tableName)
    {
        $cacheKey = "lotto_hot_pairs_filtered_100_{$type}";
        
        return Cache::remember($cacheKey, 3600, function () use ($type, $tableName) {
            $rows = DB::table($tableName)->orderByDesc('id')->limit(100)->get();
            $counts = [];
            
            foreach ($rows as $row) {
                $numbers = $this->extractReds($row, $type);
                sort($numbers);
                $len = count($numbers);
                for ($i = 0; $i < $len - 1; $i++) {
                    for ($j = $i + 1; $j < $len; $j++) {
                        // 统一格式化为 "01,05"
                        $key = str_pad($numbers[$i], 2, '0', STR_PAD_LEFT) . ',' . str_pad($numbers[$j], 2, '0', STR_PAD_LEFT);
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                }
            }

            // 按频次从高到低排序
            arsort($counts);
            
            // 设定不同彩种的最低频次阈值
            // 双色球大于5次 (>=6), 大乐透大于4次 (>=5)
            $minCount = ($type === 'ssq') ? 6 : 5;

            $result = [];
            foreach ($counts as $nums => $count) {
                if ($count >= $minCount) {
                    $result[] = [
                        'nums' => $nums, 
                        'count' => $count
                    ];
                }
            }
            return $result;
        });
    }

    private function getTableName($type)
    {
        return ['ssq' => 'ssq_lotto_history', 'dlt' => 'dlt_lotto_history'][$type] ?? null;
    }

    private function extractReds($record, $type)
    {
        $fields = ['front1', 'front2', 'front3', 'front4', 'front5'];
        if ($type === 'ssq') $fields[] = 'front6';
        $res = [];
        foreach ($fields as $field) {
            if (!empty($record->$field)) $res[] = $record->$field;
        }
        return $res;
    }

    private function extractBlues($record, $type)
    {
        return ($type === 'ssq') ? [$record->back] : [$record->back1, $record->back2];
    }
}