<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class UpdateLastLoginAt
{
    public function handle($request, Closure $next)
    {
        // 使用 sanctum 守护进程检查用户是否登录
        if (Auth::guard('sanctum')->check()) {
            $user = Auth::guard('sanctum')->user();
            // 获取当前请求的客户端真实 IP
            $currentIp = $request->ip(); 
            
            // 15分钟内不重复刷数据库，避免高频接口导致数据库压力过大
            if (!$user->last_login_at || Carbon::parse($user->last_login_at)->diffInMinutes(now()) >= 15) {
                $user->timestamps = false; // 保持 updated_at 不变
                $user->last_login_at = now();
                // 更新 IP 字段
                $user->last_login_ip = $currentIp;
                $user->save();
            }
        }

        return $next($request);
    }
}