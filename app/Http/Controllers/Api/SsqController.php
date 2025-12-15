<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\LottoSsqRecommendation;

class SsqController extends Controller
{
    /**
     * 根据 IP 获取推荐号码（每次只生成 5 注）
     */
    public function recommend(Request $request)
    {
        $ip = $request->ip(); // 获取用户 IP

        if (empty($ip)) {
            return response()->json([
                'success' => false,
                'message' => '获取失败',
            ], 400);
        }

        // 1️⃣ 查询该 IP 已有多少注
        $count = LottoSsqRecommendation::where('ip', $ip)->count();

        $maxPerIp = 100; // 每个 IP 每期最多生成 100 注
        $remaining = $maxPerIp - $count;

        if ($remaining <= 0) {
            return response()->json([
                'success' => false,
                'message' => '随机次数已用完',
                'remain' => 0
            ]);
        }

        $take = min(5, $remaining); // 每次生成 5 注，剩余不足则生成剩余数量

        // 2️⃣ 查询未分配的随机号码
        $randomData = LottoSsqRecommendation::whereNull('ip')
            ->inRandomOrder()
            ->select(['id','front_numbers','back_numbers'])
            ->take($take)
            ->get();

        if ($randomData->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => '号码池不足',
            ], 404);
        }

        // 3️⃣ 更新为当前 IP
        $ids = $randomData->pluck('id')->toArray();
        DB::table('lotto_ssq_recommendations')
            ->whereIn('id', $ids)
            ->update(['ip' => $ip]);

        // 4️⃣ 返回结果
        return response()->json([
            'success' => true,
            'data' => $randomData,
            'remain' => $remaining - $take,
        ]);
    }

    /**
     * 下载当前 IP + 当前期号全部号码（TXT）
     */
    public function download(Request $request)
    {
        $ip = $request->ip();

        if (empty($ip)) {
            return response()->json(['success'=>false, 'message'=>'获取失败'], 400);
        }

        $list = LottoSsqRecommendation::where('ip', $ip)
            ->orderBy('id')
            ->get();

        if ($list->isEmpty()) {
            return response()->json(['success'=>false, 'message'=>'暂无可下载数据'],404);
        }

        $content = '';
        foreach ($list as $index => $row) {
            $content .= sprintf(
                "%02d. 红球:%s | 蓝球:%s\n",
                $index + 1,
                $row->front_numbers,
                $row->back_numbers
            );
        }

        $filename = "ssq.txt";

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\""
        ]);
    }
}
