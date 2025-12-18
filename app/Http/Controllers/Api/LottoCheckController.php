<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class LottoCheckController extends Controller
{
    /**
     * 查询前区号码是否在机选库（不补零）
     */
    public function checkFrontExists(Request $request)
    {
        $type = $request->input('type'); // ssq / dlt
        $frontNumbers = $request->input('front_numbers'); // 用户输入，如 "1,3,12,8,22"

        // 拆分并去掉空格
        $nums = preg_split('/[,\s]+/', $frontNumbers);
        $nums = array_map('trim', $nums);

        // 用逗号连接，不补零
        $frontStr = implode(',', $nums);

        // 根据彩种选择表和字段
        if ($type === 'ssq') {
            $table = 'lotto_ssq_recommendations';
            $field = 'front_numbers'; // 前区字段
        } else {
            $table = 'lotto_dlt_recommendations';
            $field = 'front_numbers';
        }

        $query = DB::table($table)
            ->where($field, $frontStr);

        $count = $query->count();

        $latest = [];
        if ($count > 0) {
            $latest = $query->orderBy('id', 'desc')
                            ->take(5)
                            ->get(['id', 'created_at']);
        }

        return response()->json([
            'exists' => $count > 0,
            'count'  => $count,
            'latest' => $latest
        ]);
    }
}
