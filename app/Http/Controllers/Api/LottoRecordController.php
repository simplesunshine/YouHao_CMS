<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LottoRecordController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->input('type', 'ssq'); 
        $pageSize = $request->input('pageSize', 20);
        $userId = Auth::id();

        // 1. 使用统一表名查询
        $records = DB::table('user_lotto_records')
            ->where('user_id', $userId)
            ->where('lottery_type', $type)
            ->orderBy('id', 'desc')
            ->paginate($pageSize);

        $historyTable = ($type === 'ssq') ? 'ssq_lotto_history' : 'dlt_lotto_history';

        // 2. 批量获取开奖结果
        $issues = $records->pluck('issue')->unique()->toArray();
        $openResults = DB::table($historyTable)
            ->whereIn('issue', $issues)
            ->get()
            ->keyBy('issue');

        // 辅助解析函数
        $parseNumbers = function($str) {
            if (!$str) return [];
            $parts = preg_split('/[,\s]+/', trim($str));
            return array_values(array_filter(array_map('intval', $parts), fn($v) => $v > 0));
        };

        // 3. 构造返回结构
        $items = collect($records->items())->map(function ($item) use ($openResults, $parseNumbers) {
            // 解析用户选择的号码
            $userDan = $parseNumbers($item->red_dan);      // 胆码
            $userRed = $parseNumbers($item->red_numbers);  // 拖码或单式红球
            $userBlue = $parseNumbers($item->blue_numbers); // 蓝球/后区

            // 合并所有红球用于计算命中数
            $allUserReds = array_unique(array_merge($userDan, $userRed));

            $hit_front = [];
            $hit_back = [];
            $is_win = $item->is_win;

            if (isset($openResults[$item->issue])) {
                $opened = $openResults[$item->issue];
                $openedFront = $parseNumbers($opened->front_numbers);
                $openedBack = $parseNumbers($opened->back_numbers);

                // 计算命中
                $hit_front = array_values(array_intersect($allUserReds, $openedFront));
                $hit_back = array_values(array_intersect($userBlue, $openedBack));

                if ($is_win == 0) $is_win = 2; // 已开奖
            }

            return [
                'id' => $item->id,
                'issue' => $item->issue,
                'type' => $item->lottery_type,
                'mode' => $item->mode,         // 模式：normal, dantuo, kill 等
                'is_fushi' => $item->is_fushi, // 是否复式
                'dan' => $userDan,             // 胆码数组
                'front' => $userRed,           // 拖码或红球数组
                'back' => $userBlue,
                'hit_front' => $hit_front, 
                'hit_back' => $hit_back,
                'match_count' => [
                    'red' => count($hit_front),
                    'blue' => count($hit_back)
                ],
                'is_win' => $is_win,
                'created_at' => $item->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
            ]
        ]);
    }
}