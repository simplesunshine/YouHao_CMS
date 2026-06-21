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

            $form->display('created_at');
            $form->display('updated_at');

            // =========================================================================
            // 核心逻辑升级：精准定位期号、防未来数据污染
            // =========================================================================
            $form->saved(function (Form $form) {
                // 如果当前保存的彩种不是双色球（type=1），则直接跳过
                if ($form->model()->type != 1) {
                    return;
                }

                // 获取当前表单提交的期号
                $inputIssue = $form->model()->issue;
                if (empty($inputIssue)) {
                    return;
                }

                // 1. 尝试在历史表中寻找匹配当前表单输入期号的记录
                $currentLotto = DB::table('ssq_lotto_history')
                    ->where('issue', $inputIssue)
                    ->first();

                // 2. 核心降级与寻找前一期 front1 的逻辑
                if (!$currentLotto) {
                    // 情况 A：没查到对应期号，使用历史表最后一期（最新一期）
                    $targetLotto = DB::table('ssq_lotto_history')
                        ->orderBy('issue', 'desc')
                        ->first();

                    if (!$targetLotto) return; // 历史表彻底空无数据则退出

                    $targetFront1 = $targetLotto->front1;
                    $calcIssue = $targetLotto->issue; // 用于 SQL 限制的边界期号
                    $updateId = $targetLotto->id;     // 待更新的目标行 ID
                } else {
                    // 情况 B：查到了对应期号。需要找这一期的“前一期”作为统计源头
                    // 用当前期号 - 1 或者是找比当前期号小的最大一个（这里用 issue < 当前期号 排序找前一期更稳健，防止期号跨年不连续）
                    $prevLotto = DB::table('ssq_lotto_history')
                        ->where('issue', '<', $currentLotto->issue)
                        ->orderBy('issue', 'desc')
                        ->first();

                    // 如果找不到前一期（说明是历史表里的第一条数据），无法统计，直接退出
                    if (!$prevLotto) return; 

                    $targetFront1 = $prevLotto->front1;
                    $calcIssue = $prevLotto->issue; // 当前处理的期号
                    $updateId = $prevLotto->id;     // 待更新的目标行 ID
                }

                // 3. 执行安全的条件切片 SQL (加入限制：next.issue <= :current_issue)
                // 这样无论我们修补哪一期的老数据，统计出来的永远是那一期（含）之前的历史50条
                $historyRecords = DB::select("
                    SELECT *
                    FROM (
                        SELECT next.*
                        FROM ssq_lotto_history AS next
                        JOIN ssq_lotto_history AS prev
                          ON next.issue = prev.issue + 1
                        WHERE prev.front1 = :front1_val
                          AND next.issue <= :current_issue
                        ORDER BY next.issue DESC
                        LIMIT 50
                    ) AS t
                    ORDER BY t.issue ASC
                ", [
                    'front1_val'    => $targetFront1,
                    'current_issue' => $inputIssue
                ]);

                if (empty($historyRecords)) {
                    return;
                }

                // 4. 初始化 1-33 号码计数桶
                $ballCounts = array_fill(1, 33, 0);

                // 5. 统计红球出现频次
                foreach ($historyRecords as $row) {
                    for ($i = 1; $i <= 6; $i++) {
                        $field = 'front' . $i;
                        $ballNumber = (int)$row->$field;
                        if ($ballNumber >= 1 && $ballNumber <= 33) {
                            $ballCounts[$ballNumber]++;
                        }
                    }
                }

                // 6. 排序并截取前10和后10
                // 计算最少（含0次）
                asort($ballCounts, SORT_NUMERIC);
                $bottomTenWithCounts = array_slice($ballCounts, 0, 10, true);
                $bottomTenKeys = array_keys($bottomTenWithCounts);

                // 计算最多
                arsort($ballCounts, SORT_NUMERIC);
                $topTenWithCounts = array_slice($ballCounts, 0, 10, true);
                $topTenKeys = array_keys($topTenWithCounts);
                
                // 数组键名重新升序，使其按 1,2,3... 这样规整排列
                sort($bottomTenKeys);
                sort($topTenKeys);

                // 7. 更新到对应的行上
                DB::table('ssq_lotto_history')
                    ->where('id', $updateId)
                    ->update([
                        'top_nums_50'    => json_encode($topTenKeys),
                        'bottom_nums_50' => json_encode($bottomTenKeys),
                        'updated_at'     => now()
                    ]);
            });
        });
    }
}