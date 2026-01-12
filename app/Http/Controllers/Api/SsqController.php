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

        // 每个 IP 每期最多 100 注
        $count = LottoSsqRecommendation::where('ip', $ip)->count();
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
                // 普通机选逻辑（原逻辑）
                if (empty($prefs)) {
                    $randomData = LottoSsqRecommendation::whereNull('ip')
                        ->inRandomOrder()
                        ->select(['id','front_numbers','back_numbers'])
                        ->take($take)
                        ->get();
                } else {
                    $query = LottoSsqRecommendation::whereNull('ip');

                    // 第一位
                    if (!empty($prefs['first'])) $query->whereIn('front_1', (array)$prefs['first']);
                    // 最后一位
                    if (!empty($prefs['last'])) $query->whereIn('front_6', (array)$prefs['last']);
                    // 和值
                    if (!empty($prefs['sum']) && count($prefs['sum'])===2){
                        $query->whereBetween('front_sum', [(int)$prefs['sum'][0], (int)$prefs['sum'][1]]);
                    }
                    // 奇偶比
                    if (!empty($prefs['odd_even'])){
                        [$odd,$even] = explode(':',$prefs['odd_even']);
                        $query->where('odd_count',(int)$odd)->where('even_count',(int)$even);
                    }
                    // 断区
                    if (isset($prefs['zone']) && in_array($prefs['zone'],[1,2,3])){
                        $query->where('zone'.$prefs['zone'].'_count',0);
                    }
                    // 包含号码
                    if (!empty($prefs['include'])){
                        $include = (array)$prefs['include'];
                        $query->where(function($q) use ($include){
                            foreach($include as $num){
                                $q->orWhere('front_1',$num)
                                  ->orWhere('front_2',$num)
                                  ->orWhere('front_3',$num)
                                  ->orWhere('front_4',$num)
                                  ->orWhere('front_5',$num)
                                  ->orWhere('front_6',$num);
                            }
                        });
                    }

                    $randomData = $query->inRandomOrder()
                        ->take($take)
                        ->select(['id','front_numbers','back_numbers'])
                        ->get();
                }
                break;

            case 'preference':
                // 偏好机选逻辑占位
                $randomData = LottoSsqRecommendation::whereNull('ip')
                    ->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            case 'history_sum':
                // 历史和值逻辑
                $excludeCount = (int)$request->input('exclude',0);
                $excludeSums = [];

                if ($excludeCount > 0) {
                    // 从历史表取最近 N 期的 front_sum
                    $excludeSums = DB::table('ssq_lotto_history')
                        ->orderByDesc('issue')
                        ->limit($excludeCount)
                        ->pluck('front_sum')
                        ->toArray();
                }

                $query = LottoSsqRecommendation::whereNull('ip');
                if (!empty($excludeSums)) {
                    $query->whereNotIn('front_sum',$excludeSums);
                }

                $randomData = $query->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            case 'history_span':
                // 历史跨度逻辑占位
                $exclude = $request->input('exclude', []);
                $query = LottoSsqRecommendation::whereNull('ip');
                if (!empty($exclude)){
                    $query->whereNotIn('span',$exclude); // 假设表中有 span 字段
                }
                $randomData = $query->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            case 'connect':
            case 'odd_even':
            case 'first_last':
                $prefs = $request->input('prefs', []);
                $query = LottoSsqRecommendation::whereNull('ip');
                if(!empty($prefs['first'])) $query->where('front_1', $prefs['first']);
                if(!empty($prefs['last']))  $query->where('front_6', $prefs['last']);
                $randomData = $query->inRandomOrder()->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            case 'zone_ratio':
            case 'advanced':
                // 其他模块逻辑占位
                $randomData = LottoSsqRecommendation::whereNull('ip')
                    ->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;

            default:
                // 默认普通机选
                $randomData = LottoSsqRecommendation::whereNull('ip')
                    ->inRandomOrder()
                    ->take($take)
                    ->select(['id','front_numbers','back_numbers'])
                    ->get();
                break;
        }

        if ($randomData->isEmpty()){
            return response()->json([
                'success'=>false,
                'message'=>'没有符合条件的号码，请放宽偏好或取消偏好',
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
