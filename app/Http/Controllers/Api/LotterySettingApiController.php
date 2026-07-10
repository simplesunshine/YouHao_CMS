<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LotterySetting;
use Illuminate\Http\Request;

class LotterySettingApiController extends Controller
{
    public function index()
    {
        $types = [1, 2]; // 双色球、大乐透

        $data = LotterySetting::whereIn('type', $types)
            ->select('type', 'issue', 'enabled')
            ->orderBy('type')
            ->orderBy('updated_at', 'desc')
            ->get()
            ->unique('type')               // 各类型只保留一条
            ->values();                    // 重建索引

        return response()->json([
            'code' => 200,
            'data' => $data,
        ]);
    }

    /**
     * 获取当前彩种期号
     * type=ssq 或 dlt
     */
    public function currentIssue(Request $request)
    {
        $type = $request->get('type', 'ssq'); // 默认 ssq
        $typeNum = $type === 'ssq' ? 1 : 2;

        // 获取最新一期
        $issue = LotterySetting::where('type', $typeNum)
            ->orderByDesc('issue')
            ->first();

        if (!$issue) {
            return response()->json([
                'code' => 404,
                'message' => '未找到当前期号'
            ]);
        }

        // 💡 核心改动：处理 JSON 字段，确保返回给前端的是一个数组
        // 如果你在 LotterySetting Model 里已经设置了 casts 转换，这里可以直接赋值
        // 否则可以用 json_decode($issue->prepare_blue_nums, true) 兜底
        $prepareBlueNums = is_array($issue->prepare_blue_nums) 
            ? $issue->prepare_blue_nums 
            : json_decode($issue->prepare_blue_nums ?? '[]', true);

        return response()->json([
            'code' => 200,
            'data' => [
                'type' => $typeNum,
                'issue' => $issue->issue,
                'enabled' => $issue->enabled,
                'prepare_blue_nums' => $prepareBlueNums // 👈 新增这个字段返回给前端
            ]
        ]);
    }

    /**
     * 获取蓝球预选历史记录列表（关联双色球开奖表）
     */
    public function bluepool(Request $request)
    {
        $type = $request->get('type', 'ssq');
        // 目前先适配双色球(1)，后续如果扩展大乐透(2)可以根据需要接入另外的开奖表
        $typeNum = $type === 'ssq' ? 1 : 2; 
        $limit = $request->get('limit', 10);

        // 1. 分页查询 lottery_settings 表中的预选配置记录（按期号倒序）
        $paginator = \DB::table('lottery_settings')
            ->where('type', $typeNum)
            ->orderByDesc('issue')
            ->paginate($limit);

        // 2. 收集当前页的所有期号
        $issues = collect($paginator->items())->pluck('issue')->toArray();

        $openResults = [];
        if ($typeNum === 1 && !empty($issues)) {
            // 3. 从双色球开奖表 ssq_lotto_history 查出这些期号对应的开奖蓝球 back
            $openResults = \DB::table('ssq_lotto_history')
                ->whereIn('issue', $issues)
                ->pluck('back', 'issue'); // 返回键值对结构，例如：['2026042' => 7, '2026041' => 12]
        }

        // 4. 组合并转换数据，输出给前端
        $formattedData = collect($paginator->items())->map(function ($item) use ($openResults) {
            // 解析 JSON 预选蓝球池
            $prepareBlueNums = is_array($item->prepare_blue_nums) 
                ? $item->prepare_blue_nums 
                : json_decode($item->prepare_blue_nums ?? '[]', true);

            return [
                'id' => $item->id,
                'issue' => $item->issue,
                'summary' => $item->summary,
                'result_note' => $item->result_note,
                'prepare_blue_nums' => $prepareBlueNums,
                // 匹配对应的开奖蓝球。如果还没开奖，这里返回 null，前端会自动展示“等待开奖...”
                'open_blue' => $openResults[$item->issue] ?? null, 
                // 格式化创建日期方便前端展示
                'date' => $item->created_at ? date('Y-m-d', strtotime($item->created_at)) : null,
            ];
        });

        return response()->json([
            'code' => 200,
            'data' => $formattedData,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total()
            ]
        ]);
    }

}
