<?php

namespace App\Admin\Controllers;

use App\Models\User;
use Dcat\Admin\Grid;
use Dcat\Admin\Form;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;

class UserController extends AdminController
{
    protected function grid()
    {
        return Grid::make(new User(), function (Grid $grid) {
            // --- 核心：设置默认倒序排序 ---
            $grid->model()->orderBy('id', 'desc');

            $grid->column('id', 'ID')->sortable();
            // 设置当前表格默认每页显示 100 条
            $grid->paginate(100); 
            
            // 展示头像，如果为空给个默认图
            $grid->column('profile_picture', '头像')->image('', 50, 50);
            
            $grid->column('name', '用户名')->copyable();
            $grid->column('email', '邮箱');
            $grid->column('phone', '手机号');

            // 使用 status 标签
            $grid->column('status', '状态')->using([
                'active'   => '正常',
                'inactive' => '禁用'
            ])->dot([
                'active'   => 'success',
                'inactive' => 'danger'
            ]);

            // 合并展示最后登录时间和 IP
            $grid->column('last_login_at', '最后登录')->display(function ($time) {
                if (!$time) return '<span class="text-secondary">从未登录</span>';
                $ip = $this->last_login_ip ?: '未知IP';
                return "<div>{$time}</div><small class='text-muted'>IP: {$ip}</small>";
            });

            $grid->column('created_at', '注册时间')->sortable();

            // 过滤器：方便搜索用户
            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id');
                $filter->like('name', '用户名');
                $filter->like('email', '邮箱');
                $filter->equal('status', '状态')->select(['active' => '正常', 'inactive' => '禁用']);
            });
        });
    }

    /**
     * 查看详情 (Show)
     */
    protected function detail($id)
    {
        return Show::make($id, new User(), function (Show $show) {
            $show->field('id');
            $show->field('name');
            $show->field('email');
            $show->field('phone');
            $show->field('status');
            $show->field('last_login_at');
            $show->field('last_login_ip');
            $show->field('created_at');
            $show->field('updated_at');
        });
    }

    /**
     * 表单 (Form) 用于新增或编辑
     */
    protected function form()
    {
        return Form::make(new User(), function (Form $form) {
            $form->display('id');
            $form->text('name')->required();
            $form->email('email')->required();
            $form->password('password')->rules('nullable|min:6');
            $form->mobile('phone');
            $form->image('profile_picture')->autoUpload();
            $form->radio('status')->options(['active' => '正常', 'inactive' => '禁用'])->default('active');
            
            // 保存前处理密码：只有填写了新密码才进行加密
            $form->saving(function (Form $form) {
                if ($form->password && $form->model()->password != $form->password) {
                    $form->password = bcrypt($form->password);
                } else {
                    $form->deleteInput('password');
                }
            });
        });
    }
}