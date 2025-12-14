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
        $ip = $request->ip(); // 获取用户 IP

        if (empty($ip)) {
            return response()->json([
                'success' => false,
                'message' => '无法获取用户 IP',
            ], 400);
        }

        //1️⃣ 查询该 IP 是否已有推荐数据
        $userData = LottoDltRecommendation::where('ip', $ip)
            ->orderBy('id', 'desc')
            ->select(['front_numbers', 'back_numbers'])
            ->get();


        if ($userData->isNotEmpty()) {
            return response()->json([
                'success' => true,
                'data' => $userData,
                'from' => 'user_existing_data',
            ]);
        }

        // 2️⃣ 如果没有，随机获取 10 组未分配的推荐数据
        $randomData = LottoDltRecommendation::whereNull('ip')
            ->inRandomOrder()
            ->select(['front_numbers', 'back_numbers'])
            ->take(15)
            ->get();

        if ($randomData->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => '随机失败',
            ], 404);
        }

        // 3️⃣ 把这些数据更新为当前 IP
        $ids = $randomData->pluck('id')->toArray();
        DB::table('lotto_dlt_recommendations')
            ->whereIn('id', $ids)
            ->update(['ip' => $ip]);

        // 4️⃣ 返回这 10 组数据
        return response()->json([
            'success' => true,
            'data' => $randomData,
            'from' => 'random_filled',
        ]);
    }
}
