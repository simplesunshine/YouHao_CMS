<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LotterySetting;

class LotteryStrategyController extends Controller
{
    public function getStrategyData($type)
    {
        $lotteryType = ($type === 'ssq') ? 1 : 2;

        // 1. 获取当期
        $current = LotterySetting::with(['strategyItems' => function($query) {
                $query->orderBy('sort_order', 'asc');
            }])
            ->where('type', $lotteryType)
            ->where('enabled', 1)
            ->orderBy('issue', 'desc')
            ->first();

        $history = [];
      
        // 2. 只有当期存在时，才去查历史
        if ($current) {
            
            $history = LotterySetting::with(['strategyItems' => function($query) {
                    $query->orderBy('sort_order', 'asc');
                }])
                ->where('type', $lotteryType)
                ->where('enabled', 1)
                ->where('issue', '<', $current->issue) // 这里要求 issue 在库里是数值或可比较格式
                ->orderBy('issue', 'desc')
                ->limit(10)
                ->get();
        }

        // 3. 统一返回标准的 JSON 结构，方便 Vue 处理
        return response()->json([
            'success' => true,
            'data' => [
                'current' => $current,
                'history' => $history
            ]
        ]);
    }
}