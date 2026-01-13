<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\LottoDltRecommendation;
use Illuminate\Support\Facades\DB;

class DltController extends Controller
{
    /**
     * 通用大乐透机选接口，根据 type 区分模块
     */
    public function pick(Request $request)
    {
        $ip = $request->ip();

        if (empty($ip)) {
            return response()->json(['success' => false, 'message' => '获取失败'], 400);
        }

        // 每个 IP 每期最多 100 注
        $count = LottoDltRecommendation::where('ip', $ip)->count();
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
        $type = $request->input('type', 'normal'); // 默认普通机选
        $prefs = $request->input('prefs', []);
        $randomData = collect();

        switch ($type) {

            case 'normal':
                // 普通机选
                $randomData = LottoDltRecommendation::whereNull('ip')
                    ->inRandomOrder()
                    ->take($take)
                    ->select(['id', 'front_numbers', 'back_numbers'])
                    ->get();
                break;

            case 'history_sum':
                // 排除历史和值
                $excludeCount = (int)$request->input('exclude',0);
                $excludeSums = [];

                if ($excludeCount > 0) {
                    $excludeSums = DB::table('dlt_lotto_history')
                        ->orderByDesc('issue')
                        ->limit($excludeCount)
                        ->pluck('sum')
                        ->toArray();
                }

                $query = LottoDltRecommendation::whereNull('ip');
                if (!empty($excludeSums)) {
                    $query->whereNotIn('front_sum', $excludeSums);
                }

                $randomData = $query->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            case 'odd_even':
                // 奇偶比
                if (empty($prefs['odd_even'])) {
                    return response()->json(['success'=>false,'message'=>'请选择奇偶比'], 400);
                }
                [$odd, $even] = explode(':', $prefs['odd_even']);
                $query = LottoDltRecommendation::whereNull('ip')
                    ->where('odd_count', (int)$odd)
                    ->where('even_count', (int)$even);
                $randomData = $query->inRandomOrder()->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            case 'first_last':
                // 首尾号
                $query = LottoDltRecommendation::whereNull('ip');
                if (!empty($prefs['first'])) $query->where('front_1', $prefs['first']);
                if (!empty($prefs['last']))  $query->where('front_5', $prefs['last']); // 大乐透前区5个
                $randomData = $query->inRandomOrder()->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;
            case 'connect':
                // 前区连号机选
                if (empty($prefs['serial'])) {
                    return response()->json([
                        'success' => false,
                        'message' => '请选择连号个数'
                    ], 400);
                }

                $consecutive = (int)$prefs['serial'];
                $query = LottoDltRecommendation::whereNull('ip')
                    ->where('consecutive_count', $consecutive); // 表字段 consecutive_count

                $randomData = $query->inRandomOrder()
                    ->take($take)
                    ->select(['id', 'front_numbers', 'back_numbers'])
                    ->get();
                break;

            default:
                // 其他模块默认普通机选
                $randomData = LottoDltRecommendation::whereNull('ip')
                    ->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;
        }

        if ($randomData->isEmpty()) {
            return response()->json([
                'success'=>false,
                'message'=>'没有符合条件的号码，请放宽偏好或取消偏好',
            ]);
        }

        // 绑定 IP
        $ids = $randomData->pluck('id')->toArray();
        LottoDltRecommendation::whereIn('id',$ids)->update(['ip'=>$ip]);

        return response()->json([
            'success'=>true,
            'data'=>$randomData,
            'remain'=>$remaining - $randomData->count(),
        ]);
    }

    /**
     * 下载当前 IP + 当前期号全部号码（TXT）
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

        if ($list->isEmpty()){
            return response()->json(['success'=>false,'message'=>'暂无可下载数据'],404);
        }

        $content = '';
        foreach($list as $index=>$row){
            $content .= sprintf(
                "%02d. 前区:%s | 后区:%s\n",
                $index+1,
                $row->front_numbers,
                $row->back_numbers
            );
        }

        $filename = "dlt.txt";

        return response($content,200,[
            'Content-Type'=>'text/plain; charset=UTF-8',
            'Content-Disposition'=>"attachment; filename=\"$filename\""
        ]);
    }
}
