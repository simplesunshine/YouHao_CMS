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

   
}
