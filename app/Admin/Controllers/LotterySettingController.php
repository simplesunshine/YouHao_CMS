<?php

namespace App\Admin\Controllers;

use App\Models\LotterySetting;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Http\Controllers\AdminController;
use Illuminate\Support\Facades\DB;

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
     * 表单页面
     */
    protected function form()
    {
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

            // --- 动态关联子表单 ---
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
            })->useTable();

            // --- 增加：在后台表单直接展示算好的同轨迹冷热号（只读展示） ---
            $form->divider('历史50期同轨迹数据参考');
            $form->display('top_nums_50', '最热10码')->with(function ($v) {
                return $v ? implode(', ', json_decode($v, true)) : '暂无数据';
            });
            $form->display('bottom_nums_50', '最冷10码')->with(function ($v) {
                return $v ? implode(', ', json_decode($v, true)) : '暂无数据';
            });

            $form->display('created_at');
            $form->display('updated_at');

            // =========================================================================
            // 核心逻辑升级（方案一）：回归预测本质，数据存入思路表，彻底隔离未来数据
            // =========================================================================
            $form->saved(function (Form $form) {
                $type = (int)$form->model()->type;
                
                // 仅处理双色球(1)和大乐透(2)
                if (!in_array($type, [1, 2])) {
                    return;
                }

                // 获取当前演算思路表单填写的期号（例如：2026070）
                $inputIssue = $form->model()->issue;
                if (empty($inputIssue)) {
                    return;
                }

                // 根据彩种动态配置参数
                if ($type === 1) {
                    $tableName  = 'ssq_lotto_history'; // 双色球历史表
                    $maxBallNum = 33;                  // 红球最大编号
                    $frontCount = 6;                   // 红球字段个数 (front1 - front6)
                } else {
                    $tableName  = 'dlt_lotto_history'; // 大乐透历史表
                    $maxBallNum = 35;                  // 大乐透前区最大编号
                    $frontCount = 5;                   // 大乐透前区字段个数 (front1 - front5)
                }

                // 【核心改变】：无论当前期开没开奖，我们为这一期思路提供参考背景时，
                // 永远只在历史表中，寻找比当前期号小的“最后一期已开奖数据”（即上期，如 2026069）
                $prevLotto = DB::table($tableName)
                    ->where('issue', '<', $inputIssue)
                    ->orderBy('issue', 'desc')
                    ->first();

                // 如果历史表里连一条更早的开奖记录都没有，无法参考统计，直接退出
                if (!$prevLotto) {
                    return;
                }

                $targetFront1 = $prevLotto->front1;
                $maxCalcIssue = $prevLotto->issue; // 关键：将统计边界死死锁定在“上一期”

                // 执行安全的条件切片 SQL（上限死锁在 $maxCalcIssue，哪怕当前期开奖并录入了历史，也绝对不参与50期计算）
                $historyRecords = DB::select("
                    SELECT *
                    FROM (
                        SELECT next.*
                        FROM {$tableName} AS next
                        JOIN {$tableName} AS prev
                          ON next.issue = prev.issue + 1
                        WHERE prev.front1 = :front1_val
                          AND next.issue <= :max_issue
                        ORDER BY next.issue DESC
                        LIMIT 50
                    ) AS t
                    ORDER BY t.issue ASC
                ", [
                    'front1_val' => $targetFront1,
                    'max_issue'  => $maxCalcIssue
                ]);

                if (empty($historyRecords)) {
                    return;
                }

                // 初始化号码计数桶（动态适配 1-33 或 1-35）
                $ballCounts = array_fill(1, $maxBallNum, 0);

                // 统计红球/前区出现频次
                foreach ($historyRecords as $row) {
                    for ($i = 1; $i <= $frontCount; $i++) {
                        $field = 'front' . $i;
                        if (isset($row->$field)) {
                            $ballNumber = (int)$row->$field;
                            if ($ballNumber >= 1 && $ballNumber <= $maxBallNum) {
                                $ballCounts[$ballNumber]++;
                            }
                        }
                    }
                }

                // 排序并截取前10和后10
                // 计算最少（含0次）
                asort($ballCounts, SORT_NUMERIC);
                $bottomTenWithCounts = array_slice($ballCounts, 0, 10, true);
                $bottomTenKeys = array_keys($bottomTenWithCounts);

                // 计算最多
                arsort($ballCounts, SORT_NUMERIC);
                $topTenWithCounts = array_slice($ballCounts, 0, 10, true);
                $topTenKeys = array_keys($topTenWithCounts);
                
                // 数组键名重新升序，使其按 1,2,3... 规整排列
                sort($bottomTenKeys);
                sort($topTenKeys);

                // 【核心改变】：将计算出来的最热/最冷号，更新回当前的【演算思路主表】，不再去动历史表
                DB::table('lottery_settings') 
                    ->where('id', $form->model()->id)
                    ->update([
                        'top_nums_50'    => json_encode($topTenKeys),
                        'bottom_nums_50' => json_encode($bottomTenKeys),
                        'updated_at'     => now()
                    ]);
            });
        });
    }
}