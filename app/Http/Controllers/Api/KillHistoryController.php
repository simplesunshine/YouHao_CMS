<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KillHistoryController extends Controller
{
    /**
     * 1. 战绩看板数据统计（累计期数、综合准确率、当前连对）
     */
    public function getKillStats(Request $request)
    {
        $type = $request->input('type', 'ssq'); // ssq 或 dlt

        // 只统计已经开奖的记录 (status > 0)
        $query = DB::table('lottery_kill_histories')
            ->where('lottery_type', $type)
            ->where('status', '>', 0);

        $totalCount = $query->count();

        if ($totalCount === 0) {
            return response()->json([
                'success' => true,
                'data' => ['total_count' => 0, 'accuracy' => '0.0', 'current_streak' => 0]
            ]);
        }

        // 统计完全预测正确的期数 (status == 1)
        $rightCount = (clone $query)->where('status', 1)->count();
        $accuracy = round(($rightCount / $totalCount) * 100, 1);

        // --- 核心算法：计算当前的连续对期数 (从最新往回查，直到遇到翻车的期数截止) ---
        $historyRows = DB::table('lottery_kill_histories')
            ->where('lottery_type', $type)
            ->where('status', '>', 0)
            ->orderBy('period', 'desc') // 按期号从新到老排序
            ->pluck('status')
            ->toArray();

        $currentStreak = 0;
        foreach ($historyRows as $status) {
            if ($status == 1) {
                $currentStreak++; // 如果全对，连对加1
            } elseif ($status == 2) {
                break; // 一旦遇到翻车(有杀错码)，连对立刻中断退出
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_count'    => $totalCount,
                'accuracy'       => (string)$accuracy,
                'current_streak' => $currentStreak
            ]
        ]);
    }

    /**
     * 2. 杀号逐期对错的分页历史列表
     */
    public function getKillList(Request $request)
    {
        $type  = $request->input('type', 'ssq');
        $page  = (int)$request->input('page', 1);
        $limit = (int)$request->input('limit', 10);
        $offset = ($page - 1) * $limit;

        // 连同还未开奖的(status=0)一起查出来，未开奖的排在最前面，已开奖的按期号倒序
        $list = DB::table('lottery_kill_histories')
            ->where('lottery_type', $type)
            ->orderBy('status', 'asc')        // 0=未开奖会排在 1和2 之前
            ->orderBy('period', 'desc')       // 期号从最新到最老
            ->offset($offset)
            ->limit($limit)
            ->get();

        // 格式化输出，确保返回的数据和 Vue 页面需要的字段百分之百对齐
        $data = [];
        foreach ($list as $row) {
            $data[] = [
                'id'                    => $row->id,
                'period'                => $row->period,
                'status'                => (int)$row->status,
                'kill_red_balls'        => json_decode($row->kill_red_balls, true) ?? [],
                'kill_blue_balls'       => json_decode($row->kill_blue_balls, true) ?? [],
                'open_red_balls'        => json_decode($row->open_red_balls, true),
                'open_blue_balls'       => json_decode($row->open_blue_balls, true),
                'wrong_kill_red_balls'  => json_decode($row->wrong_kill_red_balls, true) ?? [],
                'wrong_kill_blue_balls' => json_decode($row->wrong_kill_blue_balls, true) ?? [],
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => $data
        ]);
    }
}