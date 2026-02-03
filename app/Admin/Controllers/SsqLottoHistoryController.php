<?php

namespace App\Admin\Controllers;

use App\Models\SsqLottoHistory;
use Dcat\Admin\Grid;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Form;
use Illuminate\Support\Facades\DB;


class SsqLottoHistoryController extends AdminController
{
    // 列表页
    protected function grid()
    {
        $grid = new Grid(new SsqLottoHistory());

        $grid->column('id', 'ID')->sortable();
        $grid->column('issue', '期号')->sortable();

        // 红球（圆形球）
        $grid->column('front', '红球')->display(function () {
            $nums = [
                $this->front1,
                $this->front2,
                $this->front3,
                $this->front4,
                $this->front5,
                $this->front6,
            ];

            $html = '';
            foreach ($nums as $n) {
                $html .= "<span style='
                    display:inline-flex;
                    align-items:center;
                    justify-content:center;
                    width:26px;
                    height:26px;
                    border-radius:50%;
                    background-color:#F56C6C;
                    color:#fff;
                    font-size:13px;
                    font-weight:bold;
                    margin-right:4px;
                '>{$n}</span>";
            }
            return $html;
        });

        // 蓝球（圆形球）
        $grid->column('back', '蓝球')->display(function () {
            return "<span style='
                display:inline-flex;
                align-items:center;
                justify-content:center;
                width:26px;
                height:26px;
                border-radius:50%;
                background-color:#409EFF;
                color:#fff;
                font-size:13px;
                font-weight:bold;
            '>{$this->back}</span>";
        });

        $grid->column('weights', '权重');

        $grid->model()->orderByDesc('issue');

        // 开启添加 / 编辑 / 删除按钮
        //$grid->disableCreateButton(false); // 保留新建按钮
        //$grid->disableActions(false);      // 保留编辑/删除
        //$grid->disableRowSelector(false);  // 可选择行

        // 可筛选
        $grid->filter(function (Grid\Filter $filter) {
            $filter->equal('issue', '期号');
            $filter->between('open_date', '开奖日期')->date();
        });

        return $grid;
    }

    protected function form()
    {
        return Form::make(new SsqLottoHistory(), function (Form $form) {

            $form->text('issue', '期号')->required();

            // 红球
            $form->number('front1', '红球1')->required();
            $form->number('front2', '红球2')->required();
            $form->number('front3', '红球3')->required();
            $form->number('front4', '红球4')->required();
            $form->number('front5', '红球5')->required();
            $form->number('front6', '红球6')->required();

            // 蓝球
            $form->number('back', '蓝球')->required();

            $form->hidden('front_sum');
            $form->hidden('span');
            $form->hidden('zone_ratio');
            $form->hidden('red_cold_json'); // ⭐️ 关键：让字段可写

            $form->number('match_red')->min(0);
            $form->number('match_blue')->min(0);
            $form->number('weights');

            /**
             * 保存前：只算“本期自身特征”
             */
            $form->saving(function (Form $form) {

                $fronts = [
                    (int)$form->front1,
                    (int)$form->front2,
                    (int)$form->front3,
                    (int)$form->front4,
                    (int)$form->front5,
                    (int)$form->front6,
                ];

                sort($fronts);

                $form->front_numbers = implode(',', $fronts);
                $form->back_numbers  = (string)(int)$form->back;

                // 和值 / 跨度
                $form->front_sum = array_sum($fronts);
                $form->span      = max($fronts) - min($fronts);

                // 区间比
                $zones = [0, 0, 0];
                foreach ($fronts as $n) {
                    if ($n <= 11)      $zones[0]++;
                    elseif ($n <= 22)  $zones[1]++;
                    else               $zones[2]++;
                }
                $form->zone_ratio = implode(',', $zones);
            });
            
            // 保存后：算 red_cold_json（用 id）
            $form->saved(function (Form $form) {

                $currentId = $form->model()->id;

                $cold = [];

                for ($n = 1; $n <= 33; $n++) {
                    // 找最大 id（包含当前期），如果当前期包含这个号码，max 会等于 $currentId -> cold = 0
                    $lastId = DB::table('ssq_lotto_history')
                        ->whereRaw('? IN (front1,front2,front3,front4,front5,front6)', [$n])
                        ->max('id');

                    // 如果从未出现过，lastId 为 NULL，视为极冷，设为 currentId
                    $cold[$n] = $lastId ? ($currentId - $lastId) : $currentId;
                }

                // 写回当前这期
                DB::table('ssq_lotto_history')
                    ->where('id', $currentId)
                    ->update([
                        'red_cold_json' => json_encode($cold, JSON_UNESCAPED_UNICODE)
                    ]);
            });

        });
    }



}
