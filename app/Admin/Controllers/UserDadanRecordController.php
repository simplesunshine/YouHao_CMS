<?php

namespace App\Admin\Controllers;

use App\Models\UserDadanRecord;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;

class UserDadanRecordController extends AdminController
{
    /**
     * 页面标题
     */
    protected $title = '全网大单过滤日志';

    /**
     * 列表格展示
     */
    protected function grid()
    {
        return Grid::make(new UserDadanRecord(), function (Grid $grid) {
            $grid->model()->orderBy('id', 'desc'); 
            // 1. 基础配置
            $grid->column('id', 'ID')->sortable();
            $grid->column('user_id', '用户ID');
            $grid->column('username', '用户名')->bold();

            // 2. 彩种标识（使用 Dcat Admin 漂亮的 Label 渲染）
            $grid->column('lottery_type', '彩种类型')
                ->display(function ($type) {
                    return $type === 'ssq' ? '双色球' : '大乐透';
                })
                ->label([
                    'ssq' => 'danger',  // 双色球红色标签
                    'dlt' => 'primary', // 大乐透深蓝/蓝色标签
                ]);

            // 3. 期号与号码显示
            $grid->column('issue', '过滤期号')->sortable();
            
            // 号码过长时，用 Dcat 内置的 expand 展开查看，或者直接用大标签包裹
            $grid->column('numbers', '提交红球大底')
                ->display(function ($numbers) {
                    // 将逗号分隔的号码，转换为用小空格隔开的漂亮标签风格
                    $arr = explode(',', $numbers);
                    return collect($arr)->map(function ($num) {
                        return '<span class="label" style="background:#f1f5f9; color:#334155; margin-right:4px;">' . str_pad($num, 2, '0', STR_PAD_LEFT) . '</span>';
                    })->implode('');
                });

            $grid->column('ball_count', '红球数量')
                ->display(function ($count) {
                    return "{$count} 码";
                });

            // 4. 穿透打标的命中单数（突出显示影响行数）
            $grid->column('affected_rows', '穿透拦截注数')
                ->sortable()
                ->badge('danger'); // 红色气泡，一眼看出对大盘池过滤了多少组

            $grid->column('ip', '操作IP');
            $grid->column('created_at', '提交时间')->sortable();

            // 5. 权限控制：因为是审计日志流水，通常禁止后台手动添加和修改
            $grid->disableCreateButton();
            $grid->disableActions(); // 禁用右侧的操作按钮（编辑/删除），如需保留删除可注释此行

            // 6. 快捷过滤器（Filter）
            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('user_id', '用户ID')->width(3);
                $filter->like('username', '用户名')->width(3);
                $filter->equal('lottery_type', '彩种')->select([
                    'ssq' => '双色球',
                    'dlt' => '大乐透',
                ])->width(2);
                $filter->equal('issue', '期号')->width(3);
            });
        });
    }

    /**
     * 详情页（如果上面禁用了 actions，这里可以选填）
     */
    protected function detail($id)
    {
        return Show::make($id, new UserDadanRecord(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('user_id', '用户ID');
            $show->field('username', '用户名');
            $show->field('lottery_type', '彩种');
            $show->field('issue', '期号');
            $show->field('numbers', '原始提交号码');
            $show->field('affected_rows', '拦截单式注数');
            $show->field('ip', '操作IP');
            $show->field('created_at', '创建时间');
        });
    }
}