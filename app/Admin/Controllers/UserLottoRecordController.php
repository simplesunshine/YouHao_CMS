<?php

namespace App\Admin\Controllers;

use App\Models\UserLottoRecord;
use Dcat\Admin\Grid;
use Dcat\Admin\Http\Controllers\AdminController;
use Illuminate\Support\Facades\DB;

class UserLottoRecordController extends AdminController
{
    protected function grid()
    {
        return Grid::make(new UserLottoRecord(), function (Grid $grid) {
            // 1. 获取 URL 参数用于过滤和逻辑判断
            $type = request('lottery_type');
            $isFushi = request('is_fushi');

            // 数据过滤逻辑
            if (!empty($type)) {
                $grid->model()->where('lottery_type', (string)$type);
            }
            if (request()->has('is_fushi')) {
                $grid->model()->where('is_fushi', (int)$isFushi);
            }

            // --- 下面是列定义，必须全部写出来才会显示 ---

            $grid->model()->orderBy('id', 'desc'); 
            // 设置当前表格默认每页显示 100 条
            $grid->paginate(100); 
            // --- 修改这里 ---
            // 'user.name' 表示取关联模型 user 里的 name 字段
            // 如果你的用户表里用户名变量叫 nickname，就写 'user.nickname'
            $grid->column('user.name', '用户')->display(function ($name) {
                // 如果用户被删除了，给个默认显示
                return $name ?: "未知用户({$this->user_id})";
            })->copyable(); 
            $grid->column('issue', '期号')->label('default');

            // 定义高亮渲染逻辑
            $renderBall = function ($numbers, $lotteryType, $issue, $ballType = 'red') {
                if (empty($numbers)) return '-';

                // 调用静态方法获取开奖号
                $winData = self::getStaticWinNumbers($lotteryType, $issue);
                $winBalls = $winData[$ballType] ?? [];

                $nodes = explode(',', $numbers);
                return collect($nodes)->map(function ($n) use ($winBalls, $ballType) {
                    $val = str_pad(trim($n), 2, '0', STR_PAD_LEFT);
                    $isHit = in_array($val, $winBalls);

                    if ($ballType === 'red') {
                        $style = $isHit 
                            ? "background:#ff4d4f; color:#fff; font-weight:bold; border:1px solid #f5222d;" 
                            : "background:#fee; color:#e74c3c; border:1px solid #ffccc7;";
                    } else {
                        $style = $isHit 
                            ? "background:#1890ff; color:#fff; font-weight:bold; border:1px solid #096dd9;" 
                            : "background:#e6f7ff; color:#1890ff; border:1px solid #91d5ff;";
                    }

                    return "<span class='label' style='{$style} margin-right:2px; display:inline-block; min-width:28px; text-align:center; border-radius:50%;'>$n</span>";
                })->implode(' ');
            };

            // 渲染红球列
            $grid->column('red_numbers', '红球/前区')->display(function ($v) use ($renderBall) {
                return $renderBall($v, $this->lottery_type, $this->issue, 'red');
            });

            // 渲染蓝球列
            $grid->column('blue_numbers', '蓝球/后区')->display(function ($v) use ($renderBall) {
                return $renderBall($v, $this->lottery_type, $this->issue, 'blue');
            });

            // 如果是复式，显示胆码
            if ($isFushi == 1) {
                $grid->column('red_dan', '红胆')->badge('orange');
            }


            $grid->column('created_at', '机选时间')->sortable();

            // 禁用不需要的操作
            $grid->disableCreateButton();
            $grid->disableEditButton();

            // 过滤器
            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('user_id', '用户ID');
                $filter->equal('issue', '期号');
            });
        });
    }

    /**
     * 核心：根据你的表结构提取中奖号码
     */
    public static function getStaticWinNumbers($type, $issue)
    {
        static $cache = [];
        $key = "{$type}_{$issue}";
        if (isset($cache[$key])) return $cache[$key];

        $tableName = ($type === 'ssq') ? 'ssq_lotto_history' : 'dlt_lotto_history';
        $history = DB::table($tableName)->where('issue', $issue)->first();

        if (!$history) return ['red' => [], 'blue' => []];

        $fmt = fn($n) => str_pad(trim($n), 2, '0', STR_PAD_LEFT);

        $reds = [];
        $blues = [];

        if ($type === 'ssq') {
            // 双色球：通常是 6 红 (front1-6) + 1 蓝 (back1 或 back)
            $reds = [$fmt($history->front1), $fmt($history->front2), $fmt($history->front3), $fmt($history->front4), $fmt($history->front5), $fmt($history->front6)];
            // 顺便兼容一下你写的 back 字段
            $blueVal = $history->back ?? $history->back1 ?? null;
            $blues = $blueVal ? [$fmt($blueVal)] : [];
        } else {
            // 大乐透：5 红 (front1-5) + 2 蓝 (back1-2)
            $reds = [$fmt($history->front1), $fmt($history->front2), $fmt($history->front3), $fmt($history->front4), $fmt($history->front5)];
            $blues = [$fmt($history->back1), $fmt($history->back2)];
        }

        $cache[$key] = ['red' => $reds, 'blue' => $blues];
        return $cache[$key];
    }
}