<?php

namespace App\Admin\Controllers;

use App\Models\LotterySetting;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;

class LotterySettingController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'LotterySetting';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
protected function grid()
{
    $grid = new Grid(new LotterySetting());

    $grid->column('id', 'ID')->sortable();

    $grid->column('type', '类型')->using([
        1 => '双色球',
        2 => '大乐透',
    ])->label();

    $grid->column('issue', '期号');
    $grid->column('enabled', '启用')->switch();

    $grid->column('updated_at', '更新时间');

    $grid->disableViewButton();
    $grid->disableExport();

    return $grid;
}


    /**
     * Make a show builder.
     *
     * @param mixed $id
     * @return Show
     */
    protected function detail($id)
    {
        $show = new Show(LotterySetting::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('type', __('Type'));
        $show->field('issue', __('Issue'));
        $show->field('enabled', __('Enabled'));
        $show->field('created_at', __('Created at'));
        $show->field('updated_at', __('Updated at'));

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        return Form::make(new LotterySetting(), function (Form $form) {

            $form->select('type', '类型')
                ->options([
                    1 => '双色球',
                    2 => '大乐透',
                ])
                ->required();

            $form->text('issue', '期号')->required();

            $form->switch('enabled', '启用')->default(1);
        });
    }

}
