<?php

namespace App\Admin\Controllers;

use App\Models\DltLottoHistory;
use Dcat\Admin\Grid;
use Dcat\Admin\Http\Controllers\AdminController;

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
        });
    }

}
