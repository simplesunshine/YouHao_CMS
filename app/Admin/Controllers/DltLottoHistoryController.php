<?php

namespace App\Admin\Controllers;

use App\Models\DltLottoHistory;
use Dcat\Admin\Grid;
use Dcat\Admin\Http\Controllers\AdminController;
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
                $html .= "<span style='
                    display:inline-flex;
                    align-items:center;
                    justify-content:center;
                    width:26px;
                    height:26px;
                    border-radius:50%;
                    background-color:#F56C6C;  /* 红色 */
                    color:#fff;
                    font-size:13px;
                    font-weight:bold;
                    margin-right:4px;
                '>{$n}</span>";
            }
            return $html;
        });

        // 后区（蓝色球）
        $grid->column('back', '后区号码')->display(function () {
            $html = "<span style='
                display:inline-flex;
                align-items:center;
                justify-content:center;
                width:26px;
                height:26px;
                border-radius:50%;
                background-color:#409EFF;  /* 蓝色 */
                color:#fff;
                font-size:13px;
                font-weight:bold;
                margin-right:4px;
            '>{$this->back1}</span>";

            $html .= "<span style='
                display:inline-flex;
                align-items:center;
                justify-content:center;
                width:26px;
                height:26px;
                border-radius:50%;
                background-color:#409EFF;  /* 蓝色 */
                color:#fff;
                font-size:13px;
                font-weight:bold;
                margin-left:4px;
            '>{$this->back2}</span>";

            return $html;
        });


        $grid->column('weights', '权重');

        $grid->model()->orderByDesc('issue');

        //$grid->disableCreateButton();
        //$grid->disableActions();
        //$grid->disableRowSelector();

        $grid->filter(function (Grid\Filter $filter) {
            $filter->equal('issue', '期号');
            $filter->between('open_date', '开奖日期')->date();
        });

        return $grid;
    }


    protected function form()
    {
        return \Dcat\Admin\Form::make(new \App\Models\DltLottoHistory(), function (\Dcat\Admin\Form $form) {

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

            // 保存但不让用户编辑的字段
            $form->hidden('front_numbers');
            $form->hidden('back_numbers');
            $form->hidden('front_sum');
            $form->hidden('span');
            $form->hidden('zone_ratio');
            $form->hidden('red_ball_omission'); // 保存冷号 json
            $form->hidden('odd_count');
            $form->hidden('even_count');

            $form->number('match_red')->min(0);
            $form->number('match_blue')->min(0);
            $form->number('weights');

            // -------------------------
            // 保存前计算字段
            // -------------------------
            $form->saving(function (\Dcat\Admin\Form $form) {

                $fronts = [
                    (int)$form->front1,
                    (int)$form->front2,
                    (int)$form->front3,
                    (int)$form->front4,
                    (int)$form->front5,
                ];

                sort($fronts);

                $form->model()->front_numbers = implode(',', $fronts);
                $form->model()->back_numbers  = implode(',', [(int)$form->back1, (int)$form->back2]);

                // 和值 / 跨度
                $form->model()->front_sum = array_sum($fronts);
                $form->model()->span      = max($fronts) - min($fronts);

                // 区间比：1-12 / 13-24 / 25-35
                $zones = [0,0,0];
                foreach ($fronts as $n) {
                    if ($n >= 1 && $n <= 12)     $zones[0]++;
                    elseif ($n >= 13 && $n <= 24) $zones[1]++;
                    else                          $zones[2]++;
                }
                $form->model()->zone_ratio = implode(',', $zones);

                // 奇偶数
                $odd = 0;
                foreach ($fronts as $n) {
                    if ($n % 2 === 1) $odd++;
                }
                $form->model()->odd_count  = $odd;
                $form->model()->even_count = 5 - $odd;
            });

            $form->saved(function (\Dcat\Admin\Form $form) {

                $currentId = $form->model()->id;

                // 当前前区号码
                $currentNums = [
                    (int)$form->model()->front1,
                    (int)$form->model()->front2,
                    (int)$form->model()->front3,
                    (int)$form->model()->front4,
                    (int)$form->model()->front5,
                ];

                // -------------------------
                // 全表索引（防止ID跳号）
                // -------------------------
                $allIds = DB::table('dlt_lotto_history')
                    ->orderBy('id')
                    ->pluck('id')
                    ->toArray();

                $idIndex = [];
                foreach ($allIds as $idx => $id) {
                    $idIndex[$id] = $idx + 1;
                }

                $currentIndex = $idIndex[$currentId] ?? 0;

                // -------------------------
                // ① 当前期最大遗漏（用于展示）
                // -------------------------
                $currentCold = [];

                for ($n = 1; $n <= 35; $n++) {

                    $lastId = DB::table('dlt_lotto_history')
                        ->where('id', '<', $currentId)
                        ->whereRaw('? IN (front1,front2,front3,front4,front5)', [$n])
                        ->orderByDesc('id')
                        ->value('id');

                    $currentCold[$n] = $lastId
                        ? $currentIndex - $idIndex[$lastId]
                        : $currentIndex;
                }

                $currentMax = max($currentCold);

                $currentMaxNums = [];
                foreach ($currentNums as $num) {
                    if (($currentCold[$num] ?? 0) === $currentMax && $currentMax > 0) {
                        $currentMaxNums[] = $num;
                    }
                }

                // -------------------------
                // ② 下一期最大遗漏（用于推荐）
                // -------------------------
                $nextCold = [];

                for ($n = 1; $n <= 35; $n++) {

                    $lastId = DB::table('dlt_lotto_history')
                        ->where('id', '<=', $currentId)
                        ->whereRaw('? IN (front1,front2,front3,front4,front5)', [$n])
                        ->orderByDesc('id')
                        ->value('id');

                    $nextCold[$n] = $lastId
                        ? $currentIndex - $idIndex[$lastId]
                        : $currentIndex;
                }

                $nextMax = max($nextCold);

                $nextMaxNums = [];
                foreach ($nextCold as $num => $val) {
                    if ($val === $nextMax) {
                        $nextMaxNums[] = $num;
                    }
                }

                // -------------------------
                // ③ 位置80期未出现
                // -------------------------
                $last80 = DB::table('dlt_lotto_history')
                    ->where('id', '<', $currentId)
                    ->orderByDesc('id')
                    ->limit(80)
                    ->get(['front1','front2','front3','front4','front5']);

                $pos80Miss = [];

                foreach ($currentNums as $pos => $num) {
                    $found = false;

                    foreach ($last80 as $row) {
                        if ((int)$row->{'front'.($pos+1)} === $num) {
                            $found = true;
                            break;
                        }
                    }

                    $pos80Miss[$pos+1] = $found ? [] : [$num];
                }

                // -------------------------
                // ④ 红球全局遗漏（当前期截止）
                // -------------------------
                $ballOmission = [];

                for ($n = 1; $n <= 35; $n++) {

                    $lastHitId = DB::table('dlt_lotto_history')
                        ->where('id', '<=', $currentId)
                        ->whereRaw('? IN (front1,front2,front3,front4,front5)', [$n])
                        ->orderByDesc('id')
                        ->value('id');

                    if ($lastHitId) {
                        $ballOmission[$n] = $currentIndex - $idIndex[$lastHitId];
                    } else {
                        $ballOmission[$n] = $currentIndex;
                    }
                }

                // -------------------------
                // 保存
                // -------------------------
                DB::table('dlt_lotto_history')
                    ->where('id', $currentId)
                    ->update([
                        'red_max_miss_json'         => json_encode(array_values($currentMaxNums)),
                        'next_red_max_miss_json'    => json_encode(array_values($nextMaxNums)),
                        'red_position_80_miss_json' => json_encode($pos80Miss),
                        'red_ball_omission'         => json_encode($ballOmission, JSON_UNESCAPED_UNICODE),
                    ]);
            });

        });
    }



}
