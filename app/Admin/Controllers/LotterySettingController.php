<?php

namespace App\Admin\Controllers;

use App\Models\LotterySetting;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Http\Controllers\AdminController;

class LotterySettingController extends AdminController
{
    /**
     * 列表页面
     */
    protected function grid()
    {
        return Grid::make(new LotterySetting(), function (Grid $grid) {
            $grid->column('id', 'ID')->sortable();
            $grid->column('type')->using([1 => '双色球', 2 => '大乐透'])->label([
                1 => 'danger',
                2 => 'primary'
            ]);
            $grid->column('issue', '期号');
            $grid->column('enabled', '启用状态')->switch();
            $grid->column('created_at', '创建时间')->display(function ($v) {
                return date('Y-m-d H:i', strtotime($v));
            });

            // 按 ID 排序确保时序准确
            $grid->model()->orderByDesc('id');

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('type', '彩种')->select([1 => '双色球', 2 => '大乐透']);
                $filter->equal('issue', '期号');
            });
        });
    }

    /**
     * 表单页面（核心：实现动态增减规则）
     */
    protected function form()
    {
        // 注意：这里要加上 with('strategyItems') 保证编辑时能读到子表数据
        return Form::make(LotterySetting::with('strategyItems'), function (Form $form) {
            $form->display('id');

            // 主表基本信息
            $form->select('type', '彩种')
                ->options([1 => '双色球', 2 => '大乐透'])
                ->required();
            $form->text('issue', '期号')
                ->placeholder('如：2026055')
                ->required();
            $form->switch('enabled', '是否启用')->default(1);

            $form->divider('思路内容');

            $form->textarea('summary', '演算思路摘要')
                ->placeholder('请输入本期的核心逻辑简述...')
                ->rows(3);

            $form->text('result_note', '中奖/实战反馈')
                ->placeholder('开奖后填写，例如：命中红球4+1');

            // --- 动态关联子表单：核心部分 ---
            // 'strategyItems' 必须是你模型里定义的关联方法名
            $form->hasMany('strategyItems', '演算规则条目', function (Form\NestedForm $nested) {
                $nested->text('label', '标签')->placeholder('如:第一位')->width(3);
                
                $nested->select('rule_type', '展示样式')
                    ->options([
                        'exclude' => '排除(红)',
                        'range'   => '区间(蓝)',
                        'attr'    => '属性(绿)',
                        'global'  => '全局(橙)',
                    ])->default('exclude')->width(3);
                    
                $nested->text('content', '具体内容')->placeholder('如:排除01,05')->required();
                $nested->number('sort_order', '排序')->default(0);
            })->useTable(); // 使用表格模式可以让界面更整齐

            $form->display('created_at');
            $form->display('updated_at');
        });
    }
}