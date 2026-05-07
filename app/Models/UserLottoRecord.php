<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLottoRecord extends Model
{
    // 指定表名
    protected $table = 'user_lotto_records';

    // 彩种常量定义，方便在代码中引用
    const TYPE_SSQ = 'ssq';
    const TYPE_DLT = 'dlt';

    // 彩种名称映射
    public static $typeLabels = [
        self::TYPE_SSQ => '双色球',
        self::TYPE_DLT => '大乐透',
    ];

    /**
     * 允许批量赋值的字段
     */
    protected $fillable = [
        'user_id',
        'lottery_type',
        'is_fushi',
        'issue',
        'mode',
        'red_numbers',
        'blue_numbers',
        'red_dan',
        'kill_numbers',
        'is_win',
        'ip',
    ];

    /**
     * 原生字段类型转换
     * 这样取出的 is_fushi 和 is_win 会自动转为布尔值 (true/false)
     */
    protected $casts = [
        'is_fushi' => 'boolean',
        'is_win'   => 'boolean',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * 关联用户模型
     * 假设你的用户模型是 App\Models\User
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 修正后的获取彩种名称方法
     */
    public function getLotteryTypeName()
    {
        // 如果 lottery_type 为空，直接返回空字符串，避免作为键名传入 array_key_exists
        if (!$this->lottery_type) {
            return '';
        }
        return self::$typeLabels[$this->lottery_type] ?? $this->lottery_type;
    }

    /**
     * 格式化显示的辅助方法：获取模式名称
     */
    public function getModeName()
    {
        $modes = [
            'normal' => '常规机选',
            'dan'    => '定胆机选',
            'kill'   => '杀号机选',
        ];
        return $modes[$this->mode] ?? $this->mode;
    }
}