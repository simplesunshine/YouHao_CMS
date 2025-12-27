<?php
namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

use App\Models\LottoDltRecommendation;



class DltController extends Controller
{
    /**
     * 根据 IP 获取大乐透推荐号码（支持机选偏好）
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

        // 1️⃣ 当前 IP 已生成数量
        $count = LottoDltRecommendation::where('ip', $ip)->count();
        $maxPerIp = 100;

        if ($count >= $maxPerIp) {
            return response()->json([
                'success' => false,
                'message' => '随机次数已用完',
                'remain'  => 0,
            ]);
        }

        // 2️⃣ 本次最多生成 5 注
        $take = min(5, $maxPerIp - $count);

        // 3️⃣ 偏好参数
        $prefs = $request->input('prefs');

        // 4️⃣ 基础查询：只从未分配 IP 的号码池取
        $query = LottoDltRecommendation::whereNull('ip');

        // =========================
        // 偏好条件（有才加）
        // =========================
        if ($prefs) {

            // 第一位前区
            if (!empty($prefs['first'])) {
                $query->whereIn('front_1', $prefs['first']);
            }

            // 最后一位前区
            if (!empty($prefs['last'])) {
                $query->whereIn('front_5', $prefs['last']);
            }

            // 和值
            if (!empty($prefs['sum']) && count($prefs['sum']) === 2) {
                $query->whereBetween('front_sum', [
                    $prefs['sum'][0],
                    $prefs['sum'][1],
                ]);
            }

            // 奇偶比（前区 5 个）
            if (!empty($prefs['odd_even'])) {
                [$odd, $even] = explode(':', $prefs['odd_even']);
                $query->where('odd_count', $odd)
                    ->where('even_count', $even);
            }

            // 断区（注意：断区 = 该区为 0）
            if (isset($prefs['zone']) && in_array($prefs['zone'], [1, 2, 3])) {
                $zoneField = 'zone' . $prefs['zone'] . '_count';
                $query->where($zoneField, '=', 0);
            }

            // 包含指定红球（前提：你有 front_1 ~ front_6 字段）
            if (!empty($prefs['include'])) {
                $include = (array)$prefs['include'];

                $query->where(function ($q) use ($include) {
                    foreach ($include as $num) {
                        $q->orWhere('front_1', $num)
                        ->orWhere('front_2', $num)
                        ->orWhere('front_3', $num)
                        ->orWhere('front_4', $num)
                        ->orWhere('front_5', $num);
                    }
                });
            }
        }

        // 5️⃣ 按条件随机取
        $randomData = $query
            ->inRandomOrder()
            ->select(['id', 'front_numbers', 'back_numbers'])
            ->take($take)
            ->get();

        if ($randomData->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => '没有符合条件的号码，请放宽偏好或取消偏好',
            ]);
        }

        // 6️⃣ 绑定 IP
        $ids = $randomData->pluck('id')->toArray();

        DB::table('lotto_dlt_recommendations')
            ->whereIn('id', $ids)
            ->update(['ip' => $ip]);

        // 7️⃣ 返回
        return response()->json([
            'success' => true,
            'data'    => $randomData,
            'added'   => count($randomData),
            'total'   => $count + count($randomData),
            'remain'  => $maxPerIp - ($count + count($randomData)),
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
