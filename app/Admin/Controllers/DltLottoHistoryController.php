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

        // 前区（红色球）
        $grid->column('front', '前区号码')->display(function () {
            $nums = [$this->front1, $this->front2, $this->front3, $this->front4, $this->front5];
            $html = '';
            foreach ($nums as $n) {
                $html .= "<span style='display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background-color:#F56C6C;color:#fff;font-size:13px;font-weight:bold;margin-right:4px;'>{$n}</span>";
            }
            return $html;
        });

        // 后区（蓝色球）
        $grid->column('back', '后区号码')->display(function () {
            $html = "<span style='display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background-color:#409EFF;color:#fff;font-size:13px;font-weight:bold;margin-right:4px;'>{$this->back1}</span>";
            $html .= "<span style='display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background-color:#409EFF;color:#fff;font-size:13px;font-weight:bold;margin-left:4px;'>{$this->back2}</span>";
            return $html;
        });

        $grid->column('weights', '权重');
        $grid->model()->orderByDesc('issue');

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
            $form->text('issue', '期号')->required();

            // 前区
            $form->number('front1', '前区1')->required();
            $form->number('front2', '前区2')->required();
            $form->number('front3', '前区3')->required();
            $form->number('front4', '前区4')->required();
            $form->number('front5', '前区5')->required();

            // 后区
            $form->number('back1', '后区1')->required();
            $form->number('back2', '后区2')->required();

            // 隐藏计算字段
            $form->hidden('front_numbers');
            $form->hidden('back_numbers');
            $form->hidden('front_sum');
            $form->hidden('span');
            $form->hidden('zone_ratio');
            $form->hidden('odd_count');
            $form->hidden('even_count');

            $form->number('match_red')->default(0);
            $form->number('match_blue')->default(0);
            $form->number('weights')->default(0);

            // -------------------------
            // 保存前：基础字段计算
            // -------------------------
            $form->saving(function (Form $form) {
                $f1 = (int)$form->front1;
                $f2 = (int)$form->front2;
                $f3 = (int)$form->front3;
                $f4 = (int)$form->front4;
                $f5 = (int)$form->front5;

                $fronts = [$f1, $f2, $f3, $f4, $f5];
                sort($fronts);

                // 使用 input() 注入值
                $form->input('front_numbers', implode(',', $fronts));
                $form->input('back_numbers', implode(',', [(int)$form->back1, (int)$form->back2]));
                $form->input('front_sum', (string)array_sum($fronts));
                $form->input('span', (string)(max($fronts) - min($fronts)));

                $zones = [0, 0, 0];
                $odd = 0;
                foreach ($fronts as $n) {
                    if ($n >= 1 && $n <= 12)     $zones[0]++;
                    elseif ($n >= 13 && $n <= 24) $zones[1]++;
                    else                          $zones[2]++;
                    
                    if ($n % 2 === 1) $odd++;
                }
                $form->input('zone_ratio', implode(',', $zones));
                $form->input('odd_count', $odd);
                $form->input('even_count', 5 - $odd);
            });

            // -------------------------
            // 保存后：历史遗漏统计
            // -------------------------
            $form->saved(function (Form $form) {
                
                // 【关键修复点】：完美获取当前数据的 ID，绝不漏掉新增的情况
                $currentId = $form->getKey() ?: $form->model()->id;
                if (!$currentId) {
                    // 如果框架还是没给 ID，直接去数据库查刚才插入的最新一条 ID
                    $currentId = DB::table('dlt_lotto_history')->max('id');
                }
                
                if (!$currentId) return;

                // 重新获取当前开奖号，确保拿到刚存入数据库的真实数据
                $data = DB::table('dlt_lotto_history')->where('id', $currentId)->first();
                if (!$data) return;
                
                $currentNums = [(int)$data->front1, (int)$data->front2, (int)$data->front3, (int)$data->front4, (int)$data->front5];

                // 全表索引映射 (包含当前刚保存的数据)
                $allIds = DB::table('dlt_lotto_history')->orderBy('id', 'asc')->pluck('id')->toArray();
                $idIndex = array_flip($allIds);
                $currentIndex = $idIndex[$currentId] + 1;

                $currentCold = [];
                $nextCold = [];
                $ballOmission = [];

                for ($n = 1; $n <= 35; $n++) {
                    $queryHelper = function ($query) use ($n) {
                        $query->where(function ($q) use ($n) {
                            $q->where('front1', $n)->orWhere('front2', $n)
                              ->orWhere('front3', $n)->orWhere('front4', $n)
                              ->orWhere('front5', $n);
                        });
                    };

                    // ① 当前期之前的最大遗漏
                    $lastBeforeId = DB::table('dlt_lotto_history')
                        ->where('id', '<', $currentId)
                        ->where($queryHelper)
                        ->orderByDesc('id')
                        ->value('id');

                    $currentCold[$n] = $lastBeforeId ? ($currentIndex - ($idIndex[$lastBeforeId] + 1)) : $currentIndex;

                    // ② 截止当前期的遗漏（即下一期参考遗漏）
                    $lastIncludeId = DB::table('dlt_lotto_history')
                        ->where('id', '<=', $currentId)
                        ->where($queryHelper)
                        ->orderByDesc('id')
                        ->value('id');

                    $nextCold[$n] = $lastIncludeId ? ($currentIndex - ($idIndex[$lastIncludeId] + 1)) : $currentIndex;
                    $ballOmission[$n] = $nextCold[$n];
                }

                $curMaxVal = max($currentCold);
                $currentMaxNums = [];
                foreach ($currentNums as $num) {
                    if (($currentCold[$num] ?? 0) === $curMaxVal && $curMaxVal > 0) {
                        $currentMaxNums[] = $num;
                    }
                }

                $nextMaxVal = max($nextCold);
                $nextMaxNums = [];
                foreach ($nextCold as $num => $val) {
                    if ($val === $nextMaxVal) $nextMaxNums[] = $num;
                }

                // ③ 位置80期未出现
                $last80 = DB::table('dlt_lotto_history')
                    ->where('id', '<', $currentId)
                    ->orderByDesc('id')
                    ->limit(80)
                    ->get();

                $pos80Miss = [];
                foreach ($currentNums as $pos => $num) {
                    $col = 'front' . ($pos + 1);
                    $found = false;
                    foreach ($last80 as $row) {
                        if ((int)$row->$col === $num) {
                            $found = true;
                            break;
                        }
                    }
                    $pos80Miss[$pos + 1] = $found ? [] : [$num];
                }

                // 执行最终更新
                DB::table('dlt_lotto_history')
                    ->where('id', $currentId)
                    ->update([
                        'red_max_miss_json'         => json_encode(array_values($currentMaxNums)),
                        'next_red_max_miss_json'    => json_encode(array_values($nextMaxNums)),
                        'red_position_80_miss_json' => json_encode($pos80Miss),
                        'red_ball_omission'         => json_encode($ballOmission, JSON_UNESCAPED_UNICODE),
                        'red_cold_json'             => json_encode($ballOmission, JSON_UNESCAPED_UNICODE), 
                    ]);
            });
        });
    }
}