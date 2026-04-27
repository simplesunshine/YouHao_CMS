<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; // 必须保留，用于 API Token

class User extends Authenticatable
{
    // 移除了 HasFactory，因为它在旧版 Laravel 中不存在
    use HasApiTokens, Notifiable;

    /**
     * 该模型可以被批量赋值的属性。
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'profile_picture',
        'status',
    ];

    /**
     * 序列化时应隐藏的属性。
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * 属性转换（旧版 Laravel 语法）
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * 获取头像 URL 的访问器
     */
    public function getProfilePictureAttribute($value)
    {
        if ($value) {
            return filter_var($value, FILTER_VALIDATE_URL) ? $value : asset('storage/' . $value);
        }
        return 'https://fastly.jsdelivr.net/npm/@vant/assets/cat.jpeg';
    }
}