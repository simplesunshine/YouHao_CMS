<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\LottoSsqRecommendation;
use Illuminate\Support\Facades\DB;

class SsqController extends Controller
{
    /**
     * 通用机选接口，根据 type 区分模块
     */
    public function pick(Request $request)
    {
        $ip = $request->ip();

        if (empty($ip)) {
            return response()->json(['success' => false, 'message' => '获取失败'], 400);
        }

        // 每个 IP 每期最多 500 注
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

        $take = min(5, $remaining);
        $type = $request->input('type', 'normal'); // 默认普通机选
        $prefs = $request->input('prefs', []);
        $randomData = collect();

        $weights = [5,4,3,2,1]; // 权重档位，从高到低

        switch ($type) {
            case 'normal':
                foreach ($weights as $w) {
                    $results = LottoSsqRecommendation::whereNull('ip')
                                ->where('weight', $w)
                                ->inRandomOrder()
                                ->take($take)
                                ->select(['id','front_numbers','back_numbers'])
                                ->get();
                    if ($results->isNotEmpty()) {
                        $randomData = $results;
                        break; // 单档抽取，找到直接返回
                    }
                }
                break;

            case 'first_advantage':
                foreach ($weights as $w) {
                    $results = LottoSsqRecommendation::whereNull('ip')
                                ->whereBetween('front_1', [1,7])
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

            case 'connect':
                $consecutive = (int)($prefs['serial'] ?? 0);
                if ($consecutive <= 0) {
                    return response()->json(['success'=>false,'message'=>'请选择连号个数'],400);
                }
                foreach ($weights as $w) {
                    $results = LottoSsqRecommendation::whereNull('ip')
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
                $dan = (array)($prefs['dan'] ?? []);
                if (empty($dan)) {
                    return response()->json(['success'=>false,'message'=>'请至少选择 1 个胆码'],400);
                }
                if (count($dan) > 5) {
                    return response()->json(['success'=>false,'message'=>'胆码最多 5 个'],400);
                }
                foreach ($weights as $w) {
                    $query = LottoSsqRecommendation::whereNull('ip')->where('weight', $w);
                    foreach ($dan as $num) {
                        $query->where(function($q) use ($num){
                            $q->where('front_1',$num)
                              ->orWhere('front_2',$num)
                              ->orWhere('front_3',$num)
                              ->orWhere('front_4',$num)
                              ->orWhere('front_5',$num)
                              ->orWhere('front_6',$num);
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
                $excludeCount = (int)$request->input('exclude',0);
                $excludeSums = [];
                if ($excludeCount > 0) {
                    $excludeSums = DB::table('ssq_lotto_history')
                                    ->orderByDesc('issue')
                                    ->limit($excludeCount)
                                    ->pluck('front_sum')
                                    ->toArray();
                }
                foreach ($weights as $w) {
                    $query = LottoSsqRecommendation::whereNull('ip')->where('weight',$w);
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
                $exclude = (array)$request->input('exclude',[]);
                foreach ($weights as $w) {
                    $query = LottoSsqRecommendation::whereNull('ip')->where('weight',$w);
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
                if (empty($prefs['odd_even'])) {
                    return response()->json(['success'=>false,'message'=>'请选择奇偶比'], 400);
                }
                [$odd,$even] = explode(':',$prefs['odd_even']);
                foreach ($weights as $w) {
                    $results = LottoSsqRecommendation::whereNull('ip')
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
                $first = $prefs['first'] ?? null;
                $last  = $prefs['last'] ?? null;
                foreach ($weights as $w) {
                    $query = LottoSsqRecommendation::whereNull('ip')->where('weight',$w);
                    if ($first !== null) $query->where('front_1',$first);
                    if ($last !== null)  $query->where('front_6',$last);
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
                foreach ($weights as $w) {
                    $results = LottoSsqRecommendation::whereNull('ip')
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
        LottoSsqRecommendation::whereIn('id',$ids)->update(['ip'=>$ip]);

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

        $list = LottoSsqRecommendation::where('ip',$ip)
            ->orderBy('id')
            ->get();

        if ($list->isEmpty()){
            return response()->json(['success'=>false,'message'=>'暂无可下载数据'],404);
        }

        $content = '';
        foreach($list as $index=>$row){
            $content .= sprintf(
                "%02d. 红球:%s | 蓝球:%s\n",
                $index+1,
                $row->front_numbers,
                $row->back_numbers
            );
        }

        $filename = "ssq.txt";

        return response($content,200,[
            'Content-Type'=>'text/plain; charset=UTF-8',
            'Content-Disposition'=>"attachment; filename=\"$filename\""
        ]);
    }
}
