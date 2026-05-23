<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class DltReLiTuController extends Controller
{
        /**
     * 获取大乐透号码分布趋势（冷热度分析）
     * 对应路由：GET /api/dlt/relitu
     */
    public function index(Request $request)
    {
        // 1. 接收前端传来的样本期数参数（默认 40 期）
        $limit = (int)$request->input('limit', 40);
        
        // 2. 从历史开奖表提取最近的 N 期数据
        // 注意：请确保你的大乐透历史表名与字段名正确，这里假设表名为 dlt_lotto_history
        $history = DB::table('dlt_lotto_history')
            ->orderBy('issue', 'desc')
            ->limit($limit)
            ->get();

        if ($history->isEmpty()) {
            return response()->json([
                'success' => false, 
                'message' => '暂无历史开奖数据'
            ]);
        }

        // 3. 初始化大乐透计数器：前区（1-35 红球/绿球），后区（1-12 蓝球）
        $redStats  = array_fill(1, 35, 0);
        $blueStats = array_fill(1, 12, 0);

        // 4. 遍历历史数据进行高频频次统计
        foreach ($history as $row) {
            // 兼容处理：支持逗号分隔字符串（如 "01,05,12..."）或独立的字段形态
            $reds  = [];
            $blues = [];

            if (isset($row->front)) {
                // 如果是存储的逗号分隔字符串 "01,12,23..."
                $reds  = array_filter(array_map('intval', explode(',', $row->front)));
                $blues = array_filter(array_map('intval', explode(',', $row->back)));
            } else {
                // 如果是独立字段形态（front1 ~ front5, back1 ~ back2）
                $reds = [
                    (int)$row->front1, (int)$row->front2, (int)$row->front3, 
                    (int)$row->front4, (int)$row->front5
                ];
                $blues = [
                    (int)$row->back1, (int)$row->back2
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

        // 5. 辅助格式化函数：将统计数据转化为前端 v-for 循环期待的格式，并自动补齐前导零
        $formatData = function($stats) {
            $result = [];
            foreach ($stats as $num => $count) {
                $result[] = [
                    'number' => str_pad($num, 2, '0', STR_PAD_LEFT), // 补齐为 "01", "02" 格式
                    'count'  => $count
                ];
            }
            return $result;
        };

        // 6. 返回符合前端格式的标准化响应
        return response()->json([
            'success' => true,
            'data' => [
                'red'        => $formatData($redStats),
                'blue'       => $formatData($blueStats),
                'limit'      => $limit,
                'last_issue' => $history->first()->issue // 截止的最新一期号
            ]
        ]);
    }

}