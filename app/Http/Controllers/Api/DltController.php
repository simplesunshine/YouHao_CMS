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

        // 每个 IP 每期最多 500 注
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

        $take = min(5, $remaining);
        $type = $request->input('type', 'normal'); // 默认普通机选
        $prefs = $request->input('prefs', []);
        $randomData = collect();

        $weights = [5,4,3,2,1]; // 权重档位，从高到低

        switch ($type) {

            case 'normal':
                // 普通机选（权重优先单档抽取）
                foreach ($weights as $w) {
                    $results = LottoDltRecommendation::whereNull('ip')
                                ->where('weight', $w)
                                ->inRandomOrder()
                                ->take($take)
                                ->select(['id', 'front_numbers', 'back_numbers'])
                                ->get();
                    if ($results->isNotEmpty()) {
                        $randomData = $results;
                        break; // 单档抽取，找到直接返回
                    }
                }
                break;

            case 'first_advantage':
                // 首红优势机选
                foreach ($weights as $w) {
                    $results = LottoDltRecommendation::whereNull('ip')
                                ->whereBetween('front_1', [1,7])
                                ->where('weight', $w)
                                ->inRandomOrder()
                                ->take($take)
                                ->select(['id', 'front_numbers', 'back_numbers'])
                                ->get();
                    if ($results->isNotEmpty()) {
                        $randomData = $results;
                        break;
                    }
                }
                break;

            case 'connect':
                // 连号机选
                $consecutive = (int)($prefs['serial'] ?? 0);
                if ($consecutive <= 0) {
                    return response()->json(['success'=>false,'message'=>'请选择连号个数'],400);
                }
                foreach ($weights as $w) {
                    $results = LottoDltRecommendation::whereNull('ip')
                                ->where('consecutive_count', $consecutive)
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

            case 'dan_only':
                // 前区胆码机选
                $frontDan = (array)($prefs['front_dan'] ?? []);
                if (empty($frontDan)) {
                    return response()->json(['success'=>false,'message'=>'请至少选择 1 个前区胆码'],400);
                }
                if (count($frontDan) > 4) {
                    return response()->json(['success'=>false,'message'=>'前区胆码最多 4 个'],400);
                }

                foreach ($weights as $w) {
                    $query = LottoDltRecommendation::whereNull('ip')->where('weight', $w);
                    foreach ($frontDan as $num) {
                        $query->where(function ($q) use ($num) {
                            $q->where('front_1',$num)
                              ->orWhere('front_2',$num)
                              ->orWhere('front_3',$num)
                              ->orWhere('front_4',$num)
                              ->orWhere('front_5',$num);
                        });
                    }
                    $results = $query->inRandomOrder()->take($take)
                                ->select(['id','front_numbers','back_numbers'])
                                ->get();
                    if ($results->isNotEmpty()) {
                        $randomData = $results;
                        break;
                    }
                }
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
                foreach ($weights as $w) {
                    $query = LottoDltRecommendation::whereNull('ip')->where('weight',$w);
                    if (!empty($excludeSums)) $query->whereNotIn('front_sum',$excludeSums);
                    $results = $query->inRandomOrder()->take($take)
                                ->select(['id','front_numbers','back_numbers'])
                                ->get();
                    if ($results->isNotEmpty()) {
                        $randomData = $results;
                        break;
                    }
                }
                break;

            case 'history_span':
                // 排除历史跨度
                $exclude = (array)$request->input('exclude',[]);
                foreach ($weights as $w) {
                    $query = LottoDltRecommendation::whereNull('ip')->where('weight',$w);
                    if (!empty($exclude)) $query->whereNotIn('span',$exclude);
                    $results = $query->inRandomOrder()->take($take)
                                ->select(['id','front_numbers','back_numbers'])
                                ->get();
                    if ($results->isNotEmpty()) {
                        $randomData = $results;
                        break;
                    }
                }
                break;

            case 'odd_even':
                // 奇偶比
                if (empty($prefs['odd_even'])) {
                    return response()->json(['success'=>false,'message'=>'请选择奇偶比'], 400);
                }
                [$odd, $even] = explode(':',$prefs['odd_even']);
                foreach ($weights as $w) {
                    $results = LottoDltRecommendation::whereNull('ip')
                                ->where('weight',$w)
                                ->where('odd_count',(int)$odd)
                                ->where('even_count',(int)$even)
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

            case 'first_last':
                // 首尾号
                $first = $prefs['first'] ?? null;
                $last  = $prefs['last'] ?? null;
                foreach ($weights as $w) {
                    $query = LottoDltRecommendation::whereNull('ip')->where('weight',$w);
                    if ($first !== null) $query->where('front_1',$first);
                    if ($last !== null)  $query->where('front_5',$last);
                    $results = $query->inRandomOrder()->take($take)
                                ->select(['id','front_numbers','back_numbers'])
                                ->get();
                    if ($results->isNotEmpty()) {
                        $randomData = $results;
                        break;
                    }
                }
                break;

            default:
                // 其他模块默认普通机选
                foreach ($weights as $w) {
                    $results = LottoDltRecommendation::whereNull('ip')
                                ->where('weight',$w)
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
        }

        if ($randomData->isEmpty()) {
            return response()->json([
                'success'=>false,
                'message'=>'没有符合条件的号码，请放宽条件或等待更新',
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

        if ($list->isEmpty()) {
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
