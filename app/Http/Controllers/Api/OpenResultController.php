<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use DB;

class OpenResultController extends Controller
{
    public function latest()
    {
        // 近3期双色球
        $ssq = DB::table('ssq_lotto_history')
            ->orderByDesc('issue')
            ->limit(3)
            ->get()
            ->map(function ($item) {
                return [
                    'issue' => $item->issue,
                    'red' => [
                        $item->front1,
                        $item->front2,
                        $item->front3,
                        $item->front4,
                        $item->front5,
                        $item->front6,
                    ],
                    'blue' => $item->back,
                ];
            });

        // 近3期大乐透
        $dlt = DB::table('dlt_lotto_history')
            ->orderByDesc('issue')
            ->limit(3)
            ->get()
            ->map(function ($item) {
                return [
                    'issue' => $item->issue,
                    'front' => [
                        $item->front1,
                        $item->front2,
                        $item->front3,
                        $item->front4,
                        $item->front5,
                    ],
                    'back' => [
                        $item->back1,
                        $item->back2,
                    ],
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'ssq' => $ssq,
                'dlt' => $dlt,
            ],
        ]);
    }

    public function ssqHistory(Request $request)
    {
        $page = $request->input('page', 1);
        $pageSize = $request->input('pageSize', 10);

        $data = DB::table('ssq_lotto_history')
            ->orderByDesc('issue')
            ->skip(($page-1)*$pageSize)
            ->take($pageSize)
            ->get()
            ->map(fn($item)=>[
                'issue'=>$item->issue,
                'red'=>[$item->front1,$item->front2,$item->front3,$item->front4,$item->front5,$item->front6],
                'blue'=>$item->back
            ]);

        return response()->json(['success'=>true,'data'=>$data]);
    }

    public function ssqBiaojiHistory(Request $request)
    {
        $page = $request->input('page', 1);
        $pageSize = $request->input('pageSize', 10);

        $list = DB::table('ssq_lotto_history')
            ->orderByDesc('issue')
            ->skip(($page - 1) * $pageSize)
            ->take($pageSize)
            ->get();

        $data = $list->map(function ($item) {
            return [
                'issue' => $item->issue,
                'red' => [
                    $item->front1,
                    $item->front2,
                    $item->front3,
                    $item->front4,
                    $item->front5,
                    $item->front6,
                ],
                'blue' => $item->back,

                // 最大遗漏号码
                'red_max_miss' => $item->red_max_miss_json
                    ? json_decode($item->red_max_miss_json, true)
                    : [],

                // 50期未出号码（按位置）
                'red_50_miss_position' => $item->red_position_50_miss_json
                    ? json_decode($item->red_position_50_miss_json, true)
                    : new \stdClass(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function dltHistory(Request $request)
    {
        $page = $request->input('page', 1);
        $pageSize = $request->input('pageSize', 10);

        $data = DB::table('dlt_lotto_history')
            ->orderByDesc('issue')
            ->skip(($page-1)*$pageSize)
            ->take($pageSize)
            ->get()
            ->map(fn($item)=>[
                'issue'=>$item->issue,
                'front'=>[$item->front1,$item->front2,$item->front3,$item->front4,$item->front5],
                'back'=>[$item->back1,$item->back2]
            ]);

        return response()->json(['success'=>true,'data'=>$data]);
    }

    
}
