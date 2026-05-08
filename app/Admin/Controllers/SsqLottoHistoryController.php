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
        return Grid::make(new SsqLottoHistory(), function (Grid $grid) {
            $grid->column('id', 'ID')->sortable();
            $grid->column('issue', '期号')->sortable()->label('default');

            // --- 美化红球展示 ---
            $grid->column('front', '红球')->display(function ($val) {
                if (!$val) return '';
                $nums = explode(',', $val);
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

            // --- 美化蓝球展示 ---
            $grid->column('back', '蓝球')->display(function ($val) {
                if (!$val) return '';
                $val = str_pad($val, 2, '0', STR_PAD_LEFT);
                return "<span style='
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
                '>{$val}</span>";
            });

            $grid->model()->orderByDesc('id');

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('issue', '期号');
            });
        });
    }

    public function form()
    {
        return Form::make(new SsqLottoHistory(), function (Form $form) {
            
            // --- 编辑回填逻辑 (保留原逻辑) ---
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

            // --- 表单布局美化 ---
            $form->display('id', 'ID');
            $form->text('issue', '期号')->required()->width(4);

            // 使用 row 布局让球号横向排开
            $form->block('中奖号码录入', function (Form\BlockForm $form) {
                $form->row(function (Form\Row $row) {
                    $row->width(1)->html('<div style="text-align:center; padding-top:10px;"><span class="label" style="background:#ff4d4f">红球</span></div>');
                    $row->width(1)->number('front1', ' ')->min(1)->max(33)->required();
                    $row->width(1)->number('front2', ' ')->min(1)->max(33)->required();
                    $row->width(1)->number('front3', ' ')->min(1)->max(33)->required();
                    $row->width(1)->number('front4', ' ')->min(1)->max(33)->required();
                    $row->width(1)->number('front5', ' ')->min(1)->max(33)->required();
                    $row->width(1)->number('front6', ' ')->min(1)->max(33)->required();
                    
                    $row->width(1)->html('<div style="text-align:center; padding-top:10px;"><span class="label" style="background:#1890ff">蓝球</span></div>');
                    $row->width(1)->number('back', ' ')->min(1)->max(16)->required();
                });
            });

            // 隐藏计算字段 (保留)
            $form->hidden('front');
            $form->hidden('sum');
            $form->hidden('span');
            $form->hidden('zone_ratio');
            $form->hidden('odd_count');
            $form->hidden('even_count');

            // --------------------------------------------------
            // 1. 保存前逻辑 (完全保留你的逻辑)
            // --------------------------------------------------
            $form->saving(function (Form $form) {
                $fronts = [
                    (int)$form->front1, (int)$form->front2, (int)$form->front3,
                    (int)$form->front4, (int)$form->front5, (int)$form->front6
                ];
                sort($fronts); 

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
            // 2. 保存后逻辑 (完全保留你的逻辑)
            // --------------------------------------------------
            $form->saved(function (Form $form) {
                $currentId = $form->getKey() ?: $form->model()->id;
                if (!$currentId) $currentId = DB::table('ssq_lotto_history')->max('id');
                if (!$currentId) return;

                $data = DB::table('ssq_lotto_history')->where('id', $currentId)->first();
                $prevData = DB::table('ssq_lotto_history')->where('id', '<', $currentId)->orderBy('id', 'desc')->first();
                
                $updatePayload = [];
                $currentNums = explode(',', $data->front);

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
            });
        });
    }
}