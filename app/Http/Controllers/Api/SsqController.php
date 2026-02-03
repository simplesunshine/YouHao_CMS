<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\LottoSsqRecommendation;
use Illuminate\Support\Facades\DB;

class SsqController extends Controller
{
    /**
     * 通用机选接口
     */
    public function pick(Request $request)
    {
        $ip = $request->ip();

        if (empty($ip)) {
            return response()->json(['success' => false, 'message' => '获取失败'], 400);
        }

        // 每个 IP 最多 500 注
        $count = LottoSsqRecommendation::where('ip', $ip)->count();
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
             * 1️⃣ 普通机选（唯一加权模块）
             * =========================
             */
            case 'normal':

                $randomData = collect();

                // -------------------------
                // 获取上期开奖号码及特征
                // -------------------------
                $lastIssue = DB::table('ssq_lotto_history')
                    ->orderByDesc('issue')
                    ->first();

                if (!$lastIssue) {
                    return response()->json([
                        'success' => false,
                        'message' => '暂无历史开奖数据'
                    ]);
                }

                $lastSpan     = $lastIssue->span;
                $lastSum      = $lastIssue->front_sum;

                // -------------------------
                // 第一阶段：权重 4 或 5
                // -------------------------
                $results = LottoSsqRecommendation::whereNull('ip')
                    ->whereIn('weight', [4,5])
                    ->inRandomOrder()
                    ->take($take)
                    ->select([
                        'id',
                        'front_numbers',
                        'back_numbers',
                        'span',
                        'front_sum'
                    ])
                    ->get();

                if ($results->isEmpty()) {
                    // 第二阶段降级权重 3/2/1
                    foreach ([3,2,1] as $w) {
                        $results = LottoSsqRecommendation::whereNull('ip')
                            ->where('weight', $w)
                            ->inRandomOrder()
                            ->take($take)
                            ->select([
                                'id',
                                'front_numbers',
                                'back_numbers',
                                'span',
                                'front_sum'
                            ])
                            ->get();

                        if ($results->isNotEmpty()) {
                            break;
                        }
                    }
                }

                $randomData = $results->map(function($row) use ($lastSpan, $lastSum) {

                    return [
                        'front_numbers' => $row->front_numbers, // 不带0，占位直接数据库值
                        'back_numbers'  => $row->back_numbers,
                        'features' => [
                            'span_same'       => $row->span == $lastSpan,
                            'sum_same'        => $row->front_sum == $lastSum
                        ]
                    ];
                });

                break;



            /**
             * =========================
             * 2️⃣ 首红优势（不加权）
             * =========================
             */
            case 'first_advantage':
                $randomData = LottoSsqRecommendation::whereNull('ip')
                    ->whereBetween('front_1', [1,5])
                    ->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 3️⃣ 连号（不加权）
             * =========================
             */
            case 'connect':
                $consecutive = (int)($prefs['serial'] ?? 0);
                if ($consecutive <= 0) {
                    return response()->json(['success'=>false,'message'=>'请选择连号个数'],400);
                }

                $randomData = LottoSsqRecommendation::whereNull('ip')
                    ->where('consecutive_count', $consecutive)
                    ->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 4️⃣ 胆码（不加权）
             * =========================
             */
            case 'dan_only':
                $dan = (array)($prefs['dan'] ?? []);
                if (count($dan) < 1 || count($dan) > 5) {
                    return response()->json([
                        'success'=>false,
                        'message'=>'胆码数量必须 1–5 个'
                    ],400);
                }

                $query = LottoSsqRecommendation::whereNull('ip');
                foreach ($dan as $num) {
                    $query->where(function ($q) use ($num) {
                        $q->orWhere('front_1',$num)
                          ->orWhere('front_2',$num)
                          ->orWhere('front_3',$num)
                          ->orWhere('front_4',$num)
                          ->orWhere('front_5',$num)
                          ->orWhere('front_6',$num);
                    });
                }

                $randomData = $query->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 5️⃣ 排除历史和值（不加权）
             * =========================
             */
            case 'history_sum':
                $excludeCount = (int)$request->input('exclude',0);
                $excludeSums = [];

                if ($excludeCount > 0) {
                    $excludeSums = DB::table('ssq_lotto_history')
                        ->orderByDesc('issue')
                        ->limit($excludeCount)
                        ->pluck('front_sum')
                        ->toArray();
                }

                $query = LottoSsqRecommendation::whereNull('ip');
                if (!empty($excludeSums)) {
                    $query->whereNotIn('front_sum', $excludeSums);
                }

                $randomData = $query->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 6️⃣ 排除跨度（不加权）
             * =========================
             */
            case 'history_span':
                $exclude = (array)$request->input('exclude',[]);
                $query = LottoSsqRecommendation::whereNull('ip');

                if (!empty($exclude)) {
                    $query->whereNotIn('span', $exclude);
                }

                $randomData = $query->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 7️⃣ 奇偶比（不加权）
             * =========================
             */
            case 'odd_even':
                if (empty($prefs['odd_even'])) {
                    return response()->json(['success'=>false,'message'=>'请选择奇偶比'],400);
                }
                [$odd,$even] = explode(':',$prefs['odd_even']);

                $randomData = LottoSsqRecommendation::whereNull('ip')
                    ->where('odd_count',(int)$odd)
                    ->where('even_count',(int)$even)
                    ->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            /**
             * =========================
             * 8️⃣ 首尾号（不加权）
             * =========================
             */
            case 'first_last':
                $query = LottoSsqRecommendation::whereNull('ip');
                if (!empty($prefs['first'])) $query->where('front_1',$prefs['first']);
                if (!empty($prefs['last']))  $query->where('front_6',$prefs['last']);

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
        LottoSsqRecommendation::whereIn(
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

        $list = LottoSsqRecommendation::where('ip',$ip)
            ->orderBy('id')
            ->get();

        if ($list->isEmpty()) {
            return response()->json(['success'=>false,'message'=>'暂无可下载数据'],404);
        }

        $content = '';
        foreach ($list as $i=>$row) {
            $content .= sprintf(
                "%02d. 红球:%s | 蓝球:%s\n",
                $i+1,
                $row->front_numbers,
                $row->back_numbers
            );
        }

        return response($content,200,[
            'Content-Type'=>'text/plain; charset=UTF-8',
            'Content-Disposition'=>'attachment; filename="ssq.txt"'
        ]);
    }


    /**
     * 双色球号码分布统计接口
     */
    public function numberDistribution(Request $request)
    {
        $periods = (int) $request->query('periods', 50); // 默认近50期

        // 获取最近 $periods 期号
        $issues = DB::table('ssq_lotto_history')
            ->orderByDesc('issue')
            ->limit($periods)
            ->pluck('issue')
            ->toArray();

        if (empty($issues)) {
            return response()->json(['code' => 200, 'data' => []]);
        }

        $positions = ['front1', 'front2', 'front3', 'front4', 'front5', 'front6']; // 前五红球+蓝球/第六位
        $result = [];

        foreach ($positions as $pos) {
            // 获取该位置每个数字出现次数
            $counts = DB::table('ssq_lotto_history')
                ->select($pos . ' as number', DB::raw('COUNT(*) as count'))
                ->whereIn('issue', $issues)
                ->groupBy($pos)
                ->orderBy($pos)
                ->get()
                ->toArray();

            // 转成数组
            $result[] = array_map(function($item) {
                return ['number' => $item->number, 'count' => $item->count];
            }, $counts);
        }

        return response()->json([
            'code' => 200,
            'data' => $result
        ]);
    }
}
