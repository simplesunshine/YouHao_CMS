<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class NewsController extends Controller
{
    /**
     * 双色球资讯
     */
    public function ssq()
    {
        // 假设表名为 ssq_history，包含开奖号码+机选命中
        $list = DB::table('ssq_lotto_history')
            ->orderBy('issue', 'desc')
            ->limit(20)
            ->get([
                'id',
                'issue',
                'front1',
                'front2',
                'front3',
                'front4',
                'front5',
                'front6',
                'back',
                'match_red',
                'match_blue',
            ]);

        return response()->json([
            'success' => true,
            'data' => $list,
        ]);
    }

    /**
     * 大乐透资讯
     */
    public function dlt()
    {
        // 假设表名为 dlt_history，包含前区、后区和机选命中
        $list = DB::table('dlt_lotto_history')
            ->orderBy('issue', 'desc')
            ->limit(20)
            ->get([
                'id',
                'issue',
                'front1',
                'front2',
                'front3',
                'front4',
                'front5',
                'back1',
                'back2',
                'match_red',
                'match_blue',
            ]);

        return response()->json([
            'success' => true,
            'data' => $list,
        ]);
    }
}
