<?php

namespace App\Admin\Controllers;

use App\Models\SsqLottoHistory;
use Dcat\Admin\Grid;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Form;
use Illuminate\Support\Facades\DB;

class SsqLottoHistoryController extends AdminController
{
    protected function grid()
    {
        $grid = new Grid(new SsqLottoHistory());

        $grid->column('id', 'ID')->sortable();
        $grid->column('issue', '期号')->sortable();

        // 红球显示
        $grid->column('front', '红球')->display(function ($val) {
            if (!$val) return '';
            $nums = explode(',', $val);
            $html = '';
            foreach ($nums as $n) {
                $n = str_pad($n, 2, '0', STR_PAD_LEFT);
                $html .= "<span style='display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background-color:#F56C6C;color:#fff;font-size:13px;font-weight:bold;margin-right:4px;'>{$n}</span>";
            }
            return $html;
        });

        // 蓝球显示
        $grid->column('back', '蓝球')->display(function ($val) {
            if (!$val) return '';
            $val = str_pad($val, 2, '0', STR_PAD_LEFT);
            return "<span style='display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background-color:#409EFF;color:#fff;font-size:13px;font-weight:bold;'>{$val}</span>";
        });

        // 按 ID 排序确保时序准确
        $grid->model()->orderByDesc('id');

        $grid->filter(function (Grid\Filter $filter) {
            $filter->equal('issue', '期号');
            //$filter->between('open_date', '开奖日期')->date();
        });

        return $grid;
    }

    public function form()
    {
        return Form::make(new SsqLottoHistory(), function (Form $form) {

            // --- 新增：编辑时回填数据 ---
            $form->editing(function (Form $form) {
                $frontStr = $form->model()->front;
                if ($frontStr) {
                    $nums = explode(',', $frontStr);
                    foreach ($nums as $index => $num) {
                        $form->input('front' . ($index + 1), $num);
                    }
                }
                $form->input('back', $form->model()->back);
            });

            $form->display('id', 'ID');
            $form->text('issue', '期号')->required();
            //$form->date('open_date', '开奖日期')->default(date('Y-m-d'));

            // 表单输入（保持你原来的样式）
            $form->number('front1', '红球1')->min(1)->max(33)->required();
            $form->number('front2', '红球2')->min(1)->max(33)->required();
            $form->number('front3', '红球3')->min(1)->max(33)->required();
            $form->number('front4', '红球4')->min(1)->max(33)->required();
            $form->number('front5', '红球5')->min(1)->max(33)->required();
            $form->number('front6', '红球6')->min(1)->max(33)->required();
            $form->number('back', '蓝球')->min(1)->max(16)->required();

            // 隐藏计算字段
            $form->hidden('front');
            $form->hidden('sum');
            $form->hidden('span');
            $form->hidden('zone_ratio');
            $form->hidden('odd_count');
            $form->hidden('even_count');

            // --------------------------------------------------
            // 1. 保存前：处理基础字段
            // --------------------------------------------------
            $form->saving(function (Form $form) {
                $fronts = [
                    (int)$form->front1, (int)$form->front2, (int)$form->front3,
                    (int)$form->front4, (int)$form->front5, (int)$form->front6
                ];
                sort($fronts); // 必须排序

                // 将排序后的值回写到单个字段，保证 front1 < front2 ...
                foreach ($fronts as $index => $num) {
                    $form->input('front' . ($index + 1), $num);
                }

                $form->input('front', implode(',', $fronts));
                $form->input('back', (string)(int)$form->back);
                $form->input('sum', array_sum($fronts));
                $form->input('span', max($fronts) - min($fronts));

                $zones = [0, 0, 0];
                $odd = 0;
                foreach ($fronts as $n) {
                    if ($n <= 11) $zones[0]++;
                    elseif ($n <= 22) $zones[1]++;
                    else $zones[2]++;
                    
                    if ($n % 2 === 1) $odd++;
                }
                $form->input('zone_ratio', implode(':', $zones));
                $form->input('odd_count', $odd);
                $form->input('even_count', 6 - $odd);
            });

            // --------------------------------------------------
            // 2. 保存后：处理历史遗漏与连续性统计
            // --------------------------------------------------
            $form->saved(function (Form $form) {
                $currentId = $form->getKey() ?: $form->model()->id;
                if (!$currentId) $currentId = DB::table('ssq_lotto_history')->max('id');
                if (!$currentId) return;

                $data = DB::table('ssq_lotto_history')->where('id', $currentId)->first();
                // --- 新增：计算 sum_interval (和值间隔) ---
                // 查找在当前记录之前，出现的相同和值的最近一条记录
                $lastSameSumRecord = DB::table('ssq_lotto_history')
                    ->where('id', '<', $currentId)
                    ->where('sum', $data->sum)
                    ->orderBy('id', 'desc')
                    ->first();

                $sumInterval = 0;
                if ($lastSameSumRecord) {
                    // 间隔计算：统计这两条记录之间有多少期（包含本身）
                    // 逻辑：count(ID在两者之间的记录) + 1
                    $sumInterval = DB::table('ssq_lotto_history')
                        ->where('id', '>', $lastSameSumRecord->id)
                        ->where('id', '<=', $currentId)
                        ->count();
                }
                
                $updatePayload = [];
                $updatePayload['sum_interval'] = $sumInterval; // 将计算结果放入更新负载

                $prevData = DB::table('ssq_lotto_history')->where('id', '<', $currentId)->orderBy('id', 'desc')->first();
                
                $currentNums = explode(',', $data->front);

                // --- A. 连续性与重号统计 ---
                if ($prevData) {
                    $updatePayload['continuous_zone_count'] = ($data->zone_ratio === $prevData->zone_ratio) ? ($prevData->continuous_zone_count + 1) : 1;
                    $updatePayload['continuous_odd_count'] = ($data->odd_count === $prevData->odd_count) ? ($prevData->continuous_odd_count + 1) : 1;
                    
                    $getBig = fn($txt) => count(array_filter(explode(',', $txt), fn($v) => (int)$v >= 17));
                    $updatePayload['continuous_big_count'] = ($getBig($data->front) === $getBig($prevData->front)) ? ($prevData->continuous_big_count + 1) : 1;
                    
                    $updatePayload['continuous_sum_tail'] = ((int)$data->sum % 10 === (int)$prevData->sum % 10) ? ($prevData->continuous_sum_tail + 1) : 1;
                    $getRange = fn($s) => floor((int)$s / 10);
                    $updatePayload['continuous_sum_range'] = ($getRange($data->sum) === $getRange($prevData->sum)) ? ($prevData->continuous_sum_range + 1) : 1;
                    $updatePayload['continuous_span_count'] = ($data->span === $prevData->span) ? ($prevData->continuous_span_count + 1) : 1;
                    
                    $prevFronts = explode(',', $prevData->front);
                    $intersect = array_intersect($currentNums, $prevFronts);
                    $updatePayload['duplicate_count'] = count($intersect);
                    $updatePayload['duplicate_nums'] = implode(',', $intersect);
                } else {
                    $updatePayload = array_merge($updatePayload, [
                        'continuous_zone_count' => 1, 'continuous_odd_count' => 1, 'continuous_big_count' => 1,
                        'continuous_sum_tail' => 1, 'continuous_sum_range' => 1, 'continuous_span_count' => 1,
                        'duplicate_count' => 0, 'duplicate_nums' => ''
                    ]);
                }

                // --- B. 历史遗漏统计 ---
                $allIds = DB::table('ssq_lotto_history')->orderBy('id', 'asc')->pluck('id')->toArray();
                $idIndexMap = array_flip($allIds);
                $currentIndex = $idIndexMap[$currentId] + 1;

                $currentCold = []; $ballOmission = [];

                for ($n = 1; $n <= 33; $n++) {
                    $queryHelper = function ($query) use ($n) {
                        $query->where(function($q) use ($n) {
                            $q->where('front1', $n)->orWhere('front2', $n)
                              ->orWhere('front3', $n)->orWhere('front4', $n)
                              ->orWhere('front5', $n)->orWhere('front6', $n);
                        });
                    };

                    $lastBeforeId = DB::table('ssq_lotto_history')->where('id', '<', $currentId)->where($queryHelper)->orderByDesc('id')->value('id');
                    $currentCold[$n] = $lastBeforeId ? ($currentIndex - ($idIndexMap[$lastBeforeId] + 1)) : $currentIndex;

                    $lastIncludeId = DB::table('ssq_lotto_history')->where('id', '<=', $currentId)->where($queryHelper)->orderByDesc('id')->value('id');
                    $ballOmission[$n] = $lastIncludeId ? ($currentIndex - ($idIndexMap[$lastIncludeId] + 1)) : $currentIndex;
                }

                $curMaxVal = max($currentCold);
                $currentMaxNums = [];
                foreach ($currentNums as $num) {
                    if (($currentCold[(int)$num] ?? 0) === $curMaxVal && $curMaxVal > 0) $currentMaxNums[] = (int)$num;
                }

                $nextMaxVal = max($ballOmission);
                $nextMaxNums = [];
                foreach ($ballOmission as $num => $val) {
                    if ($val === $nextMaxVal) $nextMaxNums[] = $num;
                }

                $last80Rows = DB::table('ssq_lotto_history')->where('id', '<', $currentId)->orderByDesc('id')->limit(80)->get();
                $pos80Miss = [];
                foreach ($currentNums as $idx => $num) {
                    $posName = 'front' . ($idx + 1);
                    $found = false;
                    foreach ($last80Rows as $row) {
                        if ((int)$row->$posName === (int)$num) { $found = true; break; }
                    }
                    $pos80Miss[$idx + 1] = $found ? [] : [(int)$num];
                }

                $updatePayload['red_max_miss_json'] = json_encode(array_values($currentMaxNums));
                $updatePayload['next_red_max_miss_json'] = json_encode(array_values($nextMaxNums));
                $updatePayload['red_position_80_miss_json'] = json_encode($pos80Miss);
                $updatePayload['red_ball_omission'] = json_encode($ballOmission, JSON_UNESCAPED_UNICODE);

                DB::table('ssq_lotto_history')->where('id', $currentId)->update($updatePayload);

                // =================================================================
                // 3. 【新增逻辑】开奖后与系统预设杀号进行比对，统计对错
                // =================================================================
                // 查找该期号在系统里有没有预设的杀号记录
                $killRecord = DB::table('lottery_kill_histories')
                    ->where('lottery_type', 'ssq')
                    ->where('period', $data->issue) // $data->issue 是你刚保存的开奖期号
                    ->first();

                if ($killRecord) {
                    // 解析系统当初预判要杀掉的号码
                    $killRed  = json_decode($killRecord->kill_red_balls, true) ?? [];
                    $killBlue = json_decode($killRecord->kill_blue_balls, true) ?? [];

                    // 拿到当前真实的开奖号码（全部转整型方便比对）
                    $openRed = array_map('intval', explode(',', $data->front)); // 6个红球
                    $openBlue = [(int)$data->back]; // 1个蓝球

                    // 计算交集：如果预判杀掉的号码出现在开奖号码里，说明“杀错了”（翻车翻出来了）
                    $wrongRed  = array_values(array_intersect($killRed, $openRed));
                    $wrongBlue = array_values(array_intersect($killBlue, $openBlue));

                    // 状态判定：如果杀错的红球和蓝球全部为空，说明预测全对（状态1），否则有杀错（状态2）
                    $status = empty($wrongRed) ? 1 : 2;

                    // 回写到杀号历史表
                    DB::table('lottery_kill_histories')
                        ->where('id', $killRecord->id)
                        ->update([
                            'open_red_balls'        => json_encode($openRed),
                            'open_blue_balls'       => json_encode($openBlue),
                            'status'                => $status,
                            'wrong_kill_red_balls'  => json_encode($wrongRed),
                            'wrong_kill_blue_balls' => json_encode($wrongBlue),
                            'updated_at'            => now()
                        ]);
                }
            });
        });
    }
}