<?php

namespace App\Admin\Controllers;

use App\Models\SsqLottoHistory;
use Dcat\Admin\Grid;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Form;
use Illuminate\Support\Facades\DB;

class SsqLottoHistoryController extends AdminController
{
    protected function grid()
    {
        $grid = new Grid(new SsqLottoHistory());

        $grid->column('id', 'ID')->sortable();
        $grid->column('issue', '期号')->sortable();

        // 红球显示
        $grid->column('front', '红球')->display(function () {
            $nums = [$this->front1, $this->front2, $this->front3, $this->front4, $this->front5, $this->front6];
            $html = '';
            foreach ($nums as $n) {
                $html .= "<span style='display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background-color:#F56C6C;color:#fff;font-size:13px;font-weight:bold;margin-right:4px;'>{$n}</span>";
            }
            return $html;
        });

        // 蓝球显示
        $grid->column('back', '蓝球')->display(function () {
            return "<span style='display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background-color:#409EFF;color:#fff;font-size:13px;font-weight:bold;'>{$this->back}</span>";
        });

        $grid->column('weights', '权重');
        $grid->model()->orderByDesc('issue');

        $grid->filter(function (Grid\Filter $filter) {
            $filter->equal('issue', '期号');
            $filter->between('open_date', '开奖日期')->date();
        });

        return $grid;
    }

    public function form()
    {
        return Form::make(new SsqLottoHistory(), function (Form $form) {

            $form->display('id', 'ID');
            $form->text('issue', '期号')->required();

            // 表单输入
            $form->number('front1', '红球1')->required();
            $form->number('front2', '红球2')->required();
            $form->number('front3', '红球3')->required();
            $form->number('front4', '红球4')->required();
            $form->number('front5', '红球5')->required();
            $form->number('front6', '红球6')->required();
            $form->number('back', '蓝球')->required();

            // 隐藏计算字段（对应数据库列）
            $form->hidden('front_numbers');
            $form->hidden('back_numbers');
            $form->hidden('front_sum');
            $form->hidden('span');
            $form->hidden('zone_ratio');
            $form->hidden('odd_count');
            $form->hidden('even_count');

            $form->number('match_red')->default(0);
            $form->number('match_blue')->default(0);
            $form->number('weights')->default(0);

            // --------------------------------------------------
            // 1. 保存前：处理基础字段（一次保存的核心）
            // --------------------------------------------------
            $form->saving(function (Form $form) {
                $fronts = [
                    (int)$form->front1, (int)$form->front2, (int)$form->front3,
                    (int)$form->front4, (int)$form->front5, (int)$form->front6
                ];
                sort($fronts);

                // 使用 input 注入，确保写入数据库
                $form->input('front_numbers', implode(',', $fronts));
                $form->input('back_numbers', (string)(int)$form->back);
                $form->input('front_sum', (string)array_sum($fronts));
                $form->input('span', (string)(max($fronts) - min($fronts)));

                $zones = [0, 0, 0];
                $odd = 0;
                foreach ($fronts as $n) {
                    if ($n <= 11) $zones[0]++;
                    elseif ($n <= 22) $zones[1]++;
                    else $zones[2]++;
                    
                    if ($n % 2 === 1) $odd++;
                }
                $form->input('zone_ratio', implode(',', $zones));
                $form->input('odd_count', $odd);
                $form->input('even_count', 6 - $odd);
            });

            // --------------------------------------------------
            // 2. 保存后：处理历史遗漏统计
            // --------------------------------------------------
            $form->saved(function (Form $form) {
                // 获取当前 ID（兼容新增和编辑）
                $currentId = $form->getKey() ?: $form->model()->id;
                if (!$currentId) {
                    $currentId = DB::table('ssq_lotto_history')->max('id');
                }
                
                if (!$currentId) return;

                // 重新获取存入的数据
                $data = DB::table('ssq_lotto_history')->where('id', $currentId)->first();
                $currentNums = [(int)$data->front1, (int)$data->front2, (int)$data->front3, (int)$data->front4, (int)$data->front5, (int)$data->front6];

                // 全表排序，用于精确计算遗漏期数
                $allIds = DB::table('ssq_lotto_history')->orderBy('id', 'asc')->pluck('id')->toArray();
                $idIndexMap = array_flip($allIds);
                $currentIndex = $idIndexMap[$currentId] + 1;

                $currentCold = [];
                $ballOmission = [];

                for ($n = 1; $n <= 33; $n++) {
                    $queryHelper = function ($query) use ($n) {
                        $query->where(function($q) use ($n) {
                            $q->where('front1', $n)->orWhere('front2', $n)
                              ->orWhere('front3', $n)->orWhere('front4', $n)
                              ->orWhere('front5', $n)->orWhere('front6', $n);
                        });
                    };

                    // 本期之前
                    $lastBeforeId = DB::table('ssq_lotto_history')
                        ->where('id', '<', $currentId)
                        ->where($queryHelper)
                        ->orderByDesc('id')
                        ->value('id');
                    $currentCold[$n] = $lastBeforeId ? ($currentIndex - ($idIndexMap[$lastBeforeId] + 1)) : $currentIndex;

                    // 截止本期
                    $lastIncludeId = DB::table('ssq_lotto_history')
                        ->where('id', '<=', $currentId)
                        ->where($queryHelper)
                        ->orderByDesc('id')
                        ->value('id');
                    $ballOmission[$n] = $lastIncludeId ? ($currentIndex - ($idIndexMap[$lastIncludeId] + 1)) : $currentIndex;
                }

                // 命中最大遗漏
                $curMaxVal = max($currentCold);
                $currentMaxNums = [];
                foreach ($currentNums as $num) {
                    if (($currentCold[$num] ?? 0) === $curMaxVal && $curMaxVal > 0) {
                        $currentMaxNums[] = $num;
                    }
                }

                // 下期最大遗漏
                $nextMaxVal = max($ballOmission);
                $nextMaxNums = [];
                foreach ($ballOmission as $num => $val) {
                    if ($val === $nextMaxVal) $nextMaxNums[] = $num;
                }

                // 位置80期遗漏
                $last80Rows = DB::table('ssq_lotto_history')->where('id', '<', $currentId)->orderByDesc('id')->limit(80)->get();
                $pos80Miss = [];
                foreach ($currentNums as $idx => $num) {
                    $posName = 'front' . ($idx + 1);
                    $found = false;
                    foreach ($last80Rows as $row) {
                        if ((int)$row->$posName === $num) { $found = true; break; }
                    }
                    $pos80Miss[$idx + 1] = $found ? [] : [$num];
                }

                // 最终更新数据库（移除了 red_cold_json 以防报错）
                DB::table('ssq_lotto_history')
                    ->where('id', $currentId)
                    ->update([
                        'red_max_miss_json'         => json_encode(array_values($currentMaxNums)),
                        'next_red_max_miss_json'    => json_encode(array_values($nextMaxNums)),
                        'red_position_80_miss_json' => json_encode($pos80Miss),
                        'red_ball_omission'         => json_encode($ballOmission, JSON_UNESCAPED_UNICODE),
                    ]);
            });
        });
    }
}