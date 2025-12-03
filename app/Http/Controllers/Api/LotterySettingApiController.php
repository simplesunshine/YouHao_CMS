<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LotterySetting;

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

}
