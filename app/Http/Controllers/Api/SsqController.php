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
        $ip = $request->ip();

        if (empty($ip)) {
            return response()->json([
                'success' => false,
                'message' => '获取失败',
            ], 400);
        }

        // 每个 IP 每期最多 100 注
        $count = LottoSsqRecommendation::where('ip', $ip)->count();
        $maxPerIp = 100;
        $remaining = $maxPerIp - $count;

        if ($remaining <= 0) {
            return response()->json([
                'success' => false,
                'message' => '随机次数已用完',
                'remain' => 0
            ]);
        }

        $take = min(5, $remaining);

        $prefs = $request->input('prefs');

        // =========================
        // 没有偏好 → 原逻辑
        // =========================
        if (empty($prefs)) {
            $randomData = LottoSsqRecommendation::whereNull('ip')
                ->inRandomOrder()
                ->select(['id', 'front_numbers', 'back_numbers'])
                ->take($take)
                ->get();
        } else {
            // =========================
            // 有偏好 → 全 SQL 条件
            // =========================
            $query = LottoSsqRecommendation::whereNull('ip');

            // 第一位
            if (!empty($prefs['first'])) {
                $query->whereIn('front_1', (array)$prefs['first']);
            }

            // 最后一位
            if (!empty($prefs['last'])) {
                $query->whereIn('front_6', (array)$prefs['last']);
            }

            // 和值区间
            if (!empty($prefs['sum']) && count($prefs['sum']) === 2) {
                $query->whereBetween('front_sum', [
                    (int)$prefs['sum'][0],
                    (int)$prefs['sum'][1]
                ]);
            }

            // 奇偶比（如 3:3）
            if (!empty($prefs['odd_even'])) {
                [$odd, $even] = explode(':', $prefs['odd_even']);
                $query->where('odd_count', (int)$odd)
                    ->where('even_count', (int)$even);
            }

            // 断区：指定区间号码数量 = 0
            if (isset($prefs['zone']) && in_array($prefs['zone'], [1, 2, 3])) {
                $query->where('zone' . $prefs['zone'] . '_count', 0);
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
                        ->orWhere('front_5', $num)
                        ->orWhere('front_6', $num);
                    }
                });
            }

            $randomData = $query
                ->inRandomOrder()
                ->take($take)
                ->select(['id', 'front_numbers', 'back_numbers'])
                ->get();
        }

        if ($randomData->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => '没有符合条件的号码，请放宽偏好或取消偏好',
            ]);
        }

        // 绑定 IP
        $ids = $randomData->pluck('id')->toArray();
        LottoSsqRecommendation::whereIn('id', $ids)->update(['ip' => $ip]);

        return response()->json([
            'success' => true,
            'data' => $randomData,
            'remain' => $remaining - $randomData->count(),
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
