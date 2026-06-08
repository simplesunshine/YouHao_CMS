<?php

namespace App\Admin\Controllers;

use App\Models\DltLottoHistory;
use Dcat\Admin\Grid;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Form;
use Illuminate\Support\Facades\DB;

class DltLottoHistoryController extends AdminController
{
    protected function grid()
    {
        $grid = new Grid(new DltLottoHistory());

        $grid->column('id', 'ID')->sortable();
        $grid->column('issue', '期号')->sortable();

        // 前区（红色球）可视化展示
        $grid->column('front', '前区号码')->display(function () {
            $nums = [$this->front1, $this->front2, $this->front3, $this->front4, $this->front5];
            $html = '';
            foreach ($nums as $n) {
                $n = str_pad($n, 2, '0', STR_PAD_LEFT);
                $html .= "<span style='display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background-color:#F56C6C;color:#fff;font-size:13px;font-weight:bold;margin-right:4px;'>{$n}</span>";
            }
            return $html;
        });

        // 后区（蓝色球）可视化展示
        $grid->column('back', '后区号码')->display(function () {
            $b1 = str_pad($this->back1, 2, '0', STR_PAD_LEFT);
            $b2 = str_pad($this->back2, 2, '0', STR_PAD_LEFT);
            $html = "<span style='display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background-color:#409EFF;color:#fff;font-size:13px;font-weight:bold;margin-right:4px;'>{$b1}</span>";
            $html .= "<span style='display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background-color:#409EFF;color:#fff;font-size:13px;font-weight:bold;margin-left:4px;'>{$b2}</span>";
            return $html;
        });

        $grid->column('position', '位置');

        // 严格按 ID 倒序，确保时序逻辑直观
        $grid->model()->orderByDesc('id');

        $grid->filter(function (Grid\Filter $filter) {
            $filter->equal('issue', '期号');
            $filter->between('open_date', '开奖日期')->date();
        });

        return $grid;
    }

    protected function form()
    {
        return Form::make(new DltLottoHistory(), function (Form $form) {

            $form->display('id', 'ID');

            // 前区输入
            $form->row(function ($show) {
                $show->text('issue', '期号')->required();
                $show->width(2)->number('front1', '前区1')->required();
                $show->width(2)->number('front2', '前区2')->required();
                $show->width(2)->number('front3', '前区3')->required();
                $show->width(2)->number('front4', '前区4')->required();
                $show->width(2)->number('front5', '前区5')->required();
            });

            // 后区输入
            $form->row(function ($show) {
                $show->width(2)->number('back1', '后区1')->required();
                $show->width(2)->number('back2', '后区2')->required();
            });

            // 隐藏计算字段
            $form->hidden('front');
            $form->hidden('back');
            $form->hidden('sum');
            $form->hidden('span');
            $form->hidden('zone_ratio');
            $form->hidden('odd_count');
            $form->hidden('even_count');

            // -------------------------
            // 1. 保存前：处理基础物理属性
            // -------------------------
            $form->saving(function (Form $form) {
                $fronts = [(int)$form->front1, (int)$form->front2, (int)$form->front3, (int)$form->front4, (int)$form->front5];
                sort($fronts); 

                $form->input('front', implode(',', $fronts));
                $form->input('back', implode(',', [(int)$form->back1, (int)$form->back2]));
                $form->input('sum', array_sum($fronts));
                $form->input('span', max($fronts) - min($fronts));

                $zones = [0, 0, 0];
                $odd = 0;
                foreach ($fronts as $n) {
                    if ($n >= 1 && $n <= 12)      $zones[0]++;
                    elseif ($n >= 13 && $n <= 24)  $zones[1]++;
                    else                           $zones[2]++;
                    if ($n % 2 === 1) $odd++;
                }
                $form->input('zone_ratio', implode(':', $zones));
                $form->input('odd_count', $odd);
                $form->input('even_count', 5 - $odd);
            });

            // -------------------------
            // 2. 保存后：处理连续性统计与历史遗漏
            // -------------------------
            $form->saved(function (Form $form) {
                $currentId = $form->getKey() ?: $form->model()->id;
                if (!$currentId) $currentId = DB::table('dlt_lotto_history')->max('id');
                if (!$currentId) return;

                $data = DB::table('dlt_lotto_history')->where('id', $currentId)->first();
                if (!$data) return;
                $updatePayload = [];
                // 使用保存前生成的 front 字段 (格式如: "01,05,10,15,20")
                $positionId = DB::table('basic_dlt')
                    ->where('front', $data->front)
                    ->value('id');
                
                $updatePayload['position'] = $positionId;
                // --- 【核心新增】逻辑：计算 sum_interval (和值间隔) ---
                $lastSameSumRecord = DB::table('dlt_lotto_history')
                    ->where('id', '<', $currentId)
                    ->where('sum', $data->sum)
                    ->orderBy('id', 'desc')
                    ->first();

                $sumInterval = 0;
                if ($lastSameSumRecord) {
                    // 计算标准遗漏值：当前 ID 与上次出现相同和值的 ID 之间的记录总数
                    $sumInterval = DB::table('dlt_lotto_history')
                        ->where('id', '>', $lastSameSumRecord->id)
                        ->where('id', '<=', $currentId)
                        ->count();
                }
                $updatePayload['sum_interval'] = $sumInterval;

                $prevData = DB::table('dlt_lotto_history')->where('id', '<', $currentId)->orderBy('id', 'desc')->first();

                $currentFronts = [(int)$data->front1, (int)$data->front2, (int)$data->front3, (int)$data->front4, (int)$data->front5];
                sort($currentFronts);

                // --- 逻辑 A: 连续性字段统计 ---
                if ($prevData) {
                    $updatePayload['continuous_zone_count'] = ($data->zone_ratio === $prevData->zone_ratio) ? ($prevData->continuous_zone_count + 1) : 1;
                    $updatePayload['continuous_odd_count'] = ($data->odd_count === $prevData->odd_count) ? ($prevData->continuous_odd_count + 1) : 1;
                    
                    $getBig = fn($arr) => count(array_filter(is_array($arr) ? $arr : explode(',', $arr), fn($v) => (int)$v >= 18));
                    $updatePayload['continuous_big_count'] = ($getBig($currentFronts) === $getBig($prevData->front)) ? ($prevData->continuous_big_count + 1) : 1;
                    
                    $updatePayload['continuous_sum_tail'] = ((int)$data->sum % 10 === (int)$prevData->sum % 10) ? ($prevData->continuous_sum_tail + 1) : 1;
                    
                    $getRange = fn($s) => floor((int)$s / 10);
                    $updatePayload['continuous_sum_range'] = ($getRange($data->sum) === $getRange($prevData->sum)) ? ($prevData->continuous_sum_range + 1) : 1;
                    
                    $updatePayload['continuous_span_count'] = ($data->span === $prevData->span) ? ($prevData->continuous_span_count + 1) : 1;
                    
                    $prevFronts = explode(',', $prevData->front);
                    $intersect = array_intersect(array_map('strval', $currentFronts), array_map('strval', $prevFronts));
                    $updatePayload['duplicate_count'] = count($intersect);
                    $updatePayload['duplicate_nums'] = implode(',', $intersect);
                } else {
                    $updatePayload = array_merge($updatePayload, [
                        'continuous_zone_count' => 1, 'continuous_odd_count' => 1, 'continuous_big_count' => 1,
                        'continuous_sum_tail' => 1, 'continuous_sum_range' => 1, 'continuous_span_count' => 1,
                        'duplicate_count' => 0, 'duplicate_nums' => ''
                    ]);
                }

                // --- 逻辑 B: 历史遗漏/热度统计 ---
                $allIds = DB::table('dlt_lotto_history')->orderBy('id', 'asc')->pluck('id')->toArray();
                $idIndex = array_flip($allIds);
                $currentIndex = $idIndex[$currentId] + 1;

                $currentCold = [];
                $nextCold = [];
                $ballOmission = [];

                for ($n = 1; $n <= 35; $n++) {
                    $queryHelper = function ($query) use ($n) {
                        $query->where(function ($q) use ($n) {
                            $q->where('front1', $n)->orWhere('front2', $n)->orWhere('front3', $n)->orWhere('front4', $n)->orWhere('front5', $n);
                        });
                    };

                    $lastBeforeId = DB::table('dlt_lotto_history')->where('id', '<', $currentId)->where($queryHelper)->orderByDesc('id')->value('id');
                    $currentCold[$n] = $lastBeforeId ? ($currentIndex - ($idIndex[$lastBeforeId] + 1)) : $currentIndex;

                    $lastIncludeId = DB::table('dlt_lotto_history')->where('id', '<=', $currentId)->where($queryHelper)->orderByDesc('id')->value('id');
                    $nextCold[$n] = $lastIncludeId ? ($currentIndex - ($idIndex[$lastIncludeId] + 1)) : $currentIndex;
                    $ballOmission[$n] = $nextCold[$n];
                }

                $curMaxVal = max($currentCold);
                $currentMaxNums = [];
                foreach ($currentFronts as $num) {
                    if (($currentCold[$num] ?? 0) === $curMaxVal && $curMaxVal > 0) $currentMaxNums[] = $num;
                }

                $nextMaxVal = max($nextCold);
                $nextMaxNums = [];
                foreach ($nextCold as $num => $val) {
                    if ($val === $nextMaxVal) $nextMaxNums[] = $num;
                }

                $last80 = DB::table('dlt_lotto_history')->where('id', '<', $currentId)->orderByDesc('id')->limit(80)->get();
                $pos80Miss = [];
                foreach ($currentFronts as $pos => $num) {
                    $col = 'front' . ($pos + 1);
                    $found = false;
                    foreach ($last80 as $row) {
                        if ((int)$row->$col === $num) { $found = true; break; }
                    }
                    $pos80Miss[$pos + 1] = $found ? [] : [$num];
                }

                // 更新 Payload 中合并遗漏相关 JSON 字段，移除了 red_cold_json
                $updatePayload['red_max_miss_json'] = json_encode(array_values($currentMaxNums));
                $updatePayload['next_red_max_miss_json'] = json_encode(array_values($nextMaxNums));
                $updatePayload['red_position_80_miss_json'] = json_encode($pos80Miss);
                $updatePayload['red_ball_omission'] = json_encode($ballOmission, JSON_UNESCAPED_UNICODE);

                // 统一更新
                DB::table('dlt_lotto_history')->where('id', $currentId)->update($updatePayload);

                // =================================================================
                // 3. 【大乐透新增逻辑】开奖后与系统预设杀号进行比对，统计对错
                // =================================================================
                // 查找大乐透该期号在系统里有没有预设的杀号记录
                $killRecord = DB::table('lottery_kill_histories')
                    ->where('lottery_type', 'dlt')
                    ->where('period', $data->issue) // $data->issue 是当前保存的大乐透开奖期号
                    ->first();

                if ($killRecord) {
                    // 解析系统当初预判要杀掉的号码
                    $killRed  = json_decode($killRecord->kill_red_balls, true) ?? [];
                    $killBlue = json_decode($killRecord->kill_blue_balls, true) ?? [];

                    // 拿到当前真实的开奖号码（前区5个，后区2个，全部转整型方便比对）
                    $openRed  = array_map('intval', explode(',', $data->front)); 
                    $openBlue = [(int)$data->back1, (int)$data->back2]; // 大乐透后区包含两个蓝球

                    // 计算交集：如果预测杀掉的号码出现在真实开奖号码里，说明杀错了（翻车了）
                    $wrongRed  = array_values(array_intersect($killRed, $openRed));
                    $wrongBlue = array_values(array_intersect($killBlue, $openBlue));

                    // 状态判定：如果杀错的前区和后区全部为空，说明预测全对（状态1），否则有杀错（状态2）
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