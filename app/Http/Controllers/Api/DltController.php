<?php
namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

use App\Models\LottoDltRecommendation;



class DltController extends Controller
{
    /**
     * 根据 IP 获取大乐透推荐号码
     */
    public function recommend(Request $request)
    {
        $ip = $request->ip();

        if (empty($ip)) {
            return response()->json([
                'success' => false,
                'message' => '获取失败',
            ], 400);
        }

        // 1️⃣ 查询该 IP 已有多少注
        $count = LottoDltRecommendation::where('ip', $ip)->count();

        // 2️⃣ 如果已达到 100 注，直接返回
        if ($count >= 100) {
            return response()->json([
                'success' => false,
                'message' => '随机次数已用完',
                'remain'  => 0,
            ]);
        }

        // 3️⃣ 本次最多生成 5 注（但不能超过 100）
        $limit = min(5, 100 - $count);

        // 4️⃣ 从“未分配 IP”的号码池里随机取
        $randomData = LottoDltRecommendation::whereNull('ip')
            ->inRandomOrder()
            ->select(['id','front_numbers', 'back_numbers'])
            ->take($limit)
            ->get();

        if ($randomData->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => '号码池已空',
            ], 404);
        }

        // 5️⃣ 把这批号码绑定到当前 IP
        $ids = $randomData->pluck('id')->toArray();

        DB::table('lotto_dlt_recommendations')
            ->whereIn('id', $ids)
            ->update(['ip' => $ip]);

        // 6️⃣ 返回“本次新增的 5 注”
        return response()->json([
            'success' => true,
            'data'    => $randomData,
            'added'   => $limit,
            'total'   => $count + $limit,
            'remain'  => 100 - ($count + $limit),
        ]);
    }

    public function download(Request $request)
    {
        $ip = $request->ip();

        if (empty($ip)) {
            return response()->json(['message' => '获取失败'], 400);
        }

        // 1️⃣ 获取该 IP 的所有号码
        $list = LottoDltRecommendation::where('ip', $ip)
            ->select(['front_numbers', 'back_numbers'])
            ->get();

        if ($list->isEmpty()) {
            return response()->json(['message' => '暂无可下载号码'], 404);
        }

        // 2️⃣ 生成 TXT 内容
        $content = '';
        foreach ($list as $item) {
            $content .= '前区:' . $item->front_numbers
                    . ' | 后区:' . $item->back_numbers . "\n";
        }

        // 3️⃣ 返回下载文件
        return response($content, 200, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="dlt_numbers.txt"',
        ]);
    }


}
