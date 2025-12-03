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

        return response()->json([
            'code' => 200,
            'data' => [
                'type' => $typeNum,
                'issue' => $issue->issue,
                'enabled' => $issue->enabled
            ]
        ]);
    }

}
