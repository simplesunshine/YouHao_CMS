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

            // 把需要保存但不让用户编辑的字段都声明为 hidden
            $form->hidden('front_numbers');
            $form->hidden('back_numbers');
            $form->hidden('front_sum');
            $form->hidden('span');
            $form->hidden('zone_ratio');
            $form->hidden('red_cold_json'); // 保存冷号 json
            $form->hidden('odd_count');
            $form->hidden('even_count');

            $form->number('match_red')->min(0);
            $form->number('match_blue')->min(0);
            $form->number('weights');

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

                // 写到 model 上，确保会保存
                $form->model()->front_numbers = implode(',', $fronts);
                $form->model()->back_numbers  = (string)(int)$form->back;

                // 和值 / 跨度
                $form->model()->front_sum = array_sum($fronts);
                $form->model()->span      = max($fronts) - min($fronts);

                // 区间比
                $zones = [0, 0, 0];
                foreach ($fronts as $n) {
                    if ($n <= 11)      $zones[0]++;
                    elseif ($n <= 22)  $zones[1]++;
                    else               $zones[2]++;
                }
                $form->model()->zone_ratio = implode(',', $zones);

                // 奇偶数
                $odd = 0;
                foreach ($fronts as $n) {
                    if ($n % 2 === 1) $odd++;
                }
                $form->model()->odd_count  = $odd;
                $form->model()->even_count = 6 - $odd;
            });

            // 保存后：算 red_cold_json（用 id）
            $form->saved(function (Form $form) {

                $currentId = $form->model()->id;

                $cold = [];

                for ($n = 1; $n <= 33; $n++) {
                    $lastId = DB::table('ssq_lotto_history')
                        ->whereRaw('? IN (front1,front2,front3,front4,front5,front6)', [$n])
                        ->max('id');

                    $cold[$n] = $lastId ? ($currentId - $lastId) : $currentId;
                }

                DB::table('ssq_lotto_history')
                    ->where('id', $currentId)
                    ->update([
                        'red_cold_json' => json_encode($cold, JSON_UNESCAPED_UNICODE)
                    ]);
            });

        });
    }



}
