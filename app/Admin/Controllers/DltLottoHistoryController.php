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
        return Grid::make(new DltLottoHistory(), function (Grid $grid) {
            $grid->column('id', 'ID')->sortable();
            $grid->column('issue', '期号')->sortable()->label('default');

            // --- 前区（红色球）可视化展示 ---
            $grid->column('front', '前区号码')->display(function () {
                $nums = [$this->front1, $this->front2, $this->front3, $this->front4, $this->front5];
                $html = '<div style="display:flex; flex-wrap:nowrap;">';
                foreach ($nums as $n) {
                    $n = str_pad($n, 2, '0', STR_PAD_LEFT);
                    $html .= "<span style='
                        display:inline-flex;
                        align-items:center;
                        justify-content:center;
                        width:30px;
                        height:30px;
                        border-radius:50%;
                        background: radial-gradient(circle at 30% 30%, #ff7875, #ff4d4f);
                        color:#fff;
                        font-size:14px;
                        font-weight:bold;
                        margin-right:6px;
                        box-shadow: 0 2px 4px rgba(245, 108, 108, 0.3);
                        border: 1px solid #ff4d4f;
                    '>{$n}</span>";
                }
                $html .= '</div>';
                return $html;
            });

            // --- 后区（蓝色球）可视化展示 ---
            $grid->column('back', '后区号码')->display(function () {
                $b1 = str_pad($this->back1, 2, '0', STR_PAD_LEFT);
                $b2 = str_pad($this->back2, 2, '0', STR_PAD_LEFT);
                
                $style = "
                    display:inline-flex;
                    align-items:center;
                    justify-content:center;
                    width:30px;
                    height:30px;
                    border-radius:50%;
                    background: radial-gradient(circle at 30% 30%, #69c0ff, #1890ff);
                    color:#fff;
                    font-size:14px;
                    font-weight:bold;
                    box-shadow: 0 2px 4px rgba(64, 158, 255, 0.3);
                    border: 1px solid #1890ff;
                ";
                
                return "<div style='display:flex;'>
                            <span style='{$style} margin-right:6px;'>{$b1}</span>
                            <span style='{$style}'>{$b2}</span>
                        </div>";
            });

            $grid->model()->orderByDesc('id');

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('issue', '期号');
                $filter->between('open_date', '开奖日期')->date();
            });
        });
    }

    protected function form()
    {
        return Form::make(new DltLottoHistory(), function (Form $form) {

            $form->display('id', 'ID');
            $form->text('issue', '期号')->required()->width(4);

            // --- 表单布局美化：模拟彩票横向排列 ---
            $form->block('开奖号码录入', function (Form\BlockForm $form) {
                $form->row(function (Form\Row $row) {
                    // 前区标签与输入
                    $row->width(1)->html('<div style="text-align:center; padding-top:10px;"><span class="label" style="background:#ff4d4f">前区(红)</span></div>');
                    $row->width(1)->number('front1', ' ')->min(1)->max(35)->required();
                    $row->width(1)->number('front2', ' ')->min(1)->max(35)->required();
                    $row->width(1)->number('front3', ' ')->min(1)->max(35)->required();
                    $row->width(1)->number('front4', ' ')->min(1)->max(35)->required();
                    $row->width(1)->number('front5', ' ')->min(1)->max(35)->required();

                    // 间隔
                    $row->width(1)->html('');

                    // 后区标签与输入
                    $row->width(1)->html('<div style="text-align:center; padding-top:10px;"><span class="label" style="background:#1890ff">后区(蓝)</span></div>');
                    $row->width(1)->number('back1', ' ')->min(1)->max(12)->required();
                    $row->width(1)->number('back2', ' ')->min(1)->max(12)->required();
                });
            });

            // 隐藏计算字段 (保留)
            $form->hidden('front');
            $form->hidden('back');
            $form->hidden('sum');
            $form->hidden('span');
            $form->hidden('zone_ratio');
            $form->hidden('odd_count');
            $form->hidden('even_count');

            // --------------------------------------------------
            // 1. 保存前逻辑 (完全保留)
            // --------------------------------------------------
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
                $form->input('zone_ratio', implode(',', $zones));
                $form->input('odd_count', $odd);
                $form->input('even_count', 5 - $odd);
            });

            // --------------------------------------------------
            // 2. 保存后逻辑 (完全保留)
            // --------------------------------------------------
            $form->saved(function (Form $form) {
                $currentId = $form->getKey() ?: $form->model()->id;
                if (!$currentId) $currentId = DB::table('dlt_lotto_history')->max('id');
                if (!$currentId) return;

                $data = DB::table('dlt_lotto_history')->where('id', $currentId)->first();
                $prevData = DB::table('dlt_lotto_history')->where('id', '<', $currentId)->orderBy('id', 'desc')->first();
                if (!$data) return;

                $updatePayload = [];
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

                $updatePayload['red_max_miss_json'] = json_encode(array_values($currentMaxNums));
                $updatePayload['next_red_max_miss_json'] = json_encode(array_values($nextMaxNums));
                $updatePayload['red_position_80_miss_json'] = json_encode($pos80Miss);
                $updatePayload['red_ball_omission'] = json_encode($ballOmission, JSON_UNESCAPED_UNICODE);

                DB::table('dlt_lotto_history')->where('id', $currentId)->update($updatePayload);
            });
        });
    }
}