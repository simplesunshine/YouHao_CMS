<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\LottoDltRecommendation;
use Illuminate\Support\Facades\DB;

class DltController extends Controller
{
    /**
     * 通用大乐透机选接口
     */
    public function pick(Request $request)
    {
        $ip = $request->ip();

        if (empty($ip)) {
            return response()->json(['success' => false, 'message' => '获取失败'], 400);
        }

        // 每个 IP 最多 500 注
        $count = LottoDltRecommendation::where('ip', $ip)->count();
        $maxPerIp = 500;
        $remaining = $maxPerIp - $count;

        if ($remaining <= 0) {
            return response()->json([
                'success' => false,
                'message' => '随机次数已用完',
                'remain' => 0
            ]);
        }

        $take  = min(5, $remaining);
        $type  = $request->input('type', 'normal');
        $prefs = $request->input('prefs', []);
        $randomData = collect();


        switch ($type) {

            /**
             * =========================
             * 1️⃣ 普通机选（唯一权重模块）
             * =========================
             */
            case 'normal':

                // 第一阶段：权重 4 或 5（同级池，随便取）
                $results = LottoDltRecommendation::whereNull('ip')
                    ->whereIn('weight', [4, 5])
                    ->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();

                if ($results->isNotEmpty()) {
                    $randomData = $results;
                    break;
                }

                // 第二阶段：再依次降级
                foreach ([3, 2, 1] as $w) {
                    $results = LottoDltRecommendation::whereNull('ip')
                        ->where('weight', $w)
                        ->inRandomOrder()
                        ->take($take)
                        ->select(['id','front_numbers','back_numbers'])
                        ->get();

                    if ($results->isNotEmpty()) {
                        $randomData = $results;
                        break;
                    }
                }

                break;


            /**
             * =========================
             * 2️⃣ 首红优势（不使用权重）
             * =========================
             */
            case 'first_advantage':
                $randomData = LottoDltRecommendation::whereNull('ip')
                    ->whereBetween('front_1', [1,7])
                    ->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 3️⃣ 连号机选（不使用权重）
             * =========================
             */
            case 'connect':
                $consecutive = (int)($prefs['serial'] ?? 0);
                if ($consecutive <= 0) {
                    return response()->json(['success'=>false,'message'=>'请选择连号个数'],400);
                }

                $randomData = LottoDltRecommendation::whereNull('ip')
                    ->where('consecutive_count', $consecutive)
                    ->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 4️⃣ 前区胆码（不使用权重）
             * =========================
             */
            case 'dan_only':
                $frontDan = (array)($prefs['front_dan'] ?? []);
                if (empty($frontDan) || count($frontDan) > 4) {
                    return response()->json([
                        'success'=>false,
                        'message'=>'前区胆码数量 1-4 个'
                    ],400);
                }

                $query = LottoDltRecommendation::whereNull('ip');
                foreach ($frontDan as $num) {
                    $query->where(function ($q) use ($num) {
                        $q->orWhere('front_1',$num)
                          ->orWhere('front_2',$num)
                          ->orWhere('front_3',$num)
                          ->orWhere('front_4',$num)
                          ->orWhere('front_5',$num);
                    });
                }

                $randomData = $query->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 5️⃣ 排除历史和值（不使用权重）
             * =========================
             */
            case 'history_sum':
                $excludeCount = (int)$request->input('exclude',0);
                $excludeSums = [];

                if ($excludeCount > 0) {
                    $excludeSums = DB::table('dlt_lotto_history')
                        ->orderByDesc('issue')
                        ->limit($excludeCount)
                        ->pluck('front_sum')
                        ->toArray();
                }

                $query = LottoDltRecommendation::whereNull('ip');
                if (!empty($excludeSums)) {
                    $query->whereNotIn('front_sum',$excludeSums);
                }

                $randomData = $query->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 6️⃣ 排除历史跨度（不使用权重）
             * =========================
             */
            case 'history_span':
                $exclude = (array)$request->input('exclude',[]);
                $query = LottoDltRecommendation::whereNull('ip');

                if (!empty($exclude)) {
                    $query->whereNotIn('span',$exclude);
                }

                $randomData = $query->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 7️⃣ 奇偶比（不使用权重）
             * =========================
             */
            case 'odd_even':
                if (empty($prefs['odd_even'])) {
                    return response()->json(['success'=>false,'message'=>'请选择奇偶比'],400);
                }
                [$odd,$even] = explode(':',$prefs['odd_even']);

                $randomData = LottoDltRecommendation::whereNull('ip')
                    ->where('odd_count',(int)$odd)
                    ->where('even_count',(int)$even)
                    ->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 8️⃣ 首尾号（不使用权重）
             * =========================
             */
            case 'first_last':
                $query = LottoDltRecommendation::whereNull('ip');
                if (!empty($prefs['first'])) $query->where('front_1',$prefs['first']);
                if (!empty($prefs['last']))  $query->where('front_5',$prefs['last']);

                $randomData = $query->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            default:
                return response()->json([
                    'success'=>false,
                    'message'=>'未知机选类型'
                ],400);
        }

        if ($randomData->isEmpty()) {
            return response()->json([
                'success'=>false,
                'message'=>'没有符合条件的号码'
            ]);
        }

        // 绑定 IP
        LottoDltRecommendation::whereIn(
            'id',
            $randomData->pluck('id')->toArray()
        )->update(['ip'=>$ip]);

        return response()->json([
            'success'=>true,
            'data'=>$randomData,
            'remain'=>$remaining - $randomData->count(),
        ]);
    }

    /**
     * 下载当前 IP 的号码
     */
    public function download(Request $request)
    {
        $ip = $request->ip();

        if (empty($ip)) {
            return response()->json(['success'=>false,'message'=>'获取失败'],400);
        }

        $list = LottoDltRecommendation::where('ip',$ip)
            ->orderBy('id')
            ->get();

        if ($list->isEmpty()) {
            return response()->json(['success'=>false,'message'=>'暂无可下载数据'],404);
        }

        $content = '';
        foreach($list as $i=>$row){
            $content .= sprintf(
                "%02d. 前区:%s | 后区:%s\n",
                $i+1,
                $row->front_numbers,
                $row->back_numbers
            );
        }

        return response($content,200,[
            'Content-Type'=>'text/plain; charset=UTF-8',
            'Content-Disposition'=>'attachment; filename="dlt.txt"'
        ]);
    }
}
