<?php

namespace App\Admin\Controllers;

use App\Models\SsqLottoHistory;
use Dcat\Admin\Grid;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Form;

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

    // 添加 / 编辑表单
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

            // 保存前自动计算
            $form->saving(function (Form $form) {

                $fronts = [
                    (int)$form->front1,
                    (int)$form->front2,
                    (int)$form->front3,
                    (int)$form->front4,
                    (int)$form->front5,
                    (int)$form->front6,
                ];

                // 红球和值
                $form->front_sum = array_sum($fronts);

                // 跨度 = 最大值 - 最小值
                $form->span = max($fronts) - min($fronts);
            });
        });
    }

}
