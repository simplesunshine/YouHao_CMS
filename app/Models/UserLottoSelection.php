<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLottoSelection extends Model
{
    protected $table = 'user_lotto_selections';

    protected $fillable = [
        'user_id',
        'lottery_type',
        'issue',
        'front_numbers',
        'back_numbers',
        'mode', // 补上数据库里存在的 mode 字段
        'is_win',
        'win_detail'
    ];

    protected $casts = [
        'is_win' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 定义与用户的关联
     * 注意：Laravel 8+ 默认是 App\Models\User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 核心逻辑：获取前区数字数组
     * 修正：使用正则分割，确保兼容逗号和空格，并强制转为整数
     */
    public function getFrontAttribute(): array
    {
        if (empty($this->front_numbers)) return [];
        
        // preg_split '/[,\s]+/' 表示匹配逗号或任何空白字符（空格、换行、制表符）
        $parts = preg_split('/[,\s]+/', trim($this->front_numbers));
        
        // 过滤空字符串并转为整数
        return array_values(array_filter(array_map('intval', $parts)));
    }

    /**
     * 获取后区数字数组
     */
    public function getBackAttribute(): array
    {
        if (empty($this->back_numbers)) return [];
        
        $parts = preg_split('/[,\s]+/', trim($this->back_numbers));
        return array_values(array_filter(array_map('intval', $parts)));
    }
}