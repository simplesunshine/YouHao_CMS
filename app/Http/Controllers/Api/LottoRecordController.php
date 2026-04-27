<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserLottoSelection;
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

        // 1. 分页查询用户的机选记录
        $records = UserLottoSelection::where('user_id', $userId)
            ->where('lottery_type', $type)
            ->orderBy('id', 'desc')
            ->paginate($pageSize);

        // 2. 确定开奖历史表名
        $historyTable = ($type === 'ssq') ? 'ssq_lotto_history' : 'dlt_lotto_history';

        // 3. 批量获取开奖结果
        $issues = $records->pluck('issue')->unique()->toArray();
        $openResults = DB::table($historyTable)
            ->whereIn('issue', $issues)
            ->get()
            ->keyBy('issue');

        // 4. 辅助函数：将各种格式的字符串转为纯数字数组
        // 兼容 "01,02,03" 或 "1 2 12 33" 等格式
        $parseNumbers = function($str) {
            if (!$str) return [];
            // 正则匹配：按照逗号或空格分割
            $parts = preg_split('/[,\s]+/', trim($str));
            // 过滤空值、转为整数、重置数组索引
            return array_values(array_filter(array_map('intval', $parts), function($v) {
                return $v > 0;
            }));
        };

        // 5. 构造返回结构
        $items = $records->getCollection()->map(function ($item) use ($openResults, $parseNumbers) {
            // 获取用户机选号码 (建议在模型里定义 getFrontAttribute 确保这里是数组)
            // 如果模型没写，这里手动解析：$userFront = $parseNumbers($item->front_numbers);
            $userFront = $item->front; 
            $userBack = $item->back;

            $hit_front = [];
            $hit_back = [];
            $is_win = $item->is_win;

            if (isset($openResults[$item->issue])) {
                $opened = $openResults[$item->issue];

                // 解析历史开奖表中的号码
                $openedFront = $parseNumbers($opened->front_numbers);
                $openedBack = $parseNumbers($opened->back_numbers);

                // 计算交集：用户选的哪些号码在开奖号里
                // array_values 必须加，否则 JSON 会变成对象格式导致前端无法判断
                $hit_front = array_values(array_intersect($userFront, $openedFront));
                $hit_back = array_values(array_intersect($userBack, $openedBack));

                if ($is_win == 0) {
                    $is_win = 2; // 标记为已开奖状态
                }
            }

            return [
                'id' => $item->id,
                'issue' => $item->issue,
                'type' => $item->lottery_type,
                'front' => $userFront,
                'back' => $userBack,
                'hit_front' => $hit_front, 
                'hit_back' => $hit_back,
                'match_count' => [
                    'red' => count($hit_front),
                    'blue' => count($hit_back)
                ],
                'is_win' => $is_win,
                'win_detail' => $item->win_detail,
                'created_at' => $item->created_at->toDateTimeString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
            ],
            'message' => 'success'
        ]);
    }
}