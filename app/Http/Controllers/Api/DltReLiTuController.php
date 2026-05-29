<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\DltService; // <--- 引入大乐透服务层

class DltReLiTuController extends Controller
{
    protected $dltService;

    // 构造函数注入 Service
    public function __construct(DltService $dltService)
    {
        $this->dltService = $dltService;
    }

    /**
     * 获取大乐透号码分布趋势（冷热度分析）
     * 对应路由：GET /api/dlt/relitu
     */
    public function index(Request $request)
    {
        // 1. 接收前端传来的样本期数参数（默认 40 期）
        $limit = (int)$request->input('limit', 40);
        
        // 2. 调用 Service 获取纯净的统计数据
        $data = $this->dltService->getNumberDistribution($limit);

        if (!$data) {
            return response()->json([
                'success' => false, 
                'message' => '暂无历史开奖数据'
            ]);
        }

        // 3. 返回标准化响应
        return response()->json([
            'success' => true,
            'data'    => $data
        ]);
    }
}