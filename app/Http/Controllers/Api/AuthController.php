<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    // 注册
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password), // 自动加密
            'status' => 'active',
        ]);

        return response()->json(['success' => true, 'message' => '注册成功']);
    }

    // 登录
    public function login(Request $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['success' => false, 'message' => '邮箱或密码错误'], 401);
        }

        // --- 新增：保存登录时间和IP ---
        $user->update([
            'last_login_at' => now(), // Laravel 会自动处理时间格式
            'last_login_ip' => $request->ip(), // 获取客户端真实 IP
        ]);

        // 生成 Token (使用 Sanctum)
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'profile_picture' => $user->profile_picture,
                'last_login_at' => $user->last_login_at, // 返回给前端也可以
            ],
            'token' => $token
        ]);
    }

    public function logout(Request $request)
    {
        // 获取当前认证用户
        $user = $request->user();

        if ($user) {
            // 注意：如果你使用 Sanctum，应该删除当前 Token，而不是操作 api_token 字段
            $user->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => '登出成功'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => '未找到有效登录状态'
        ], 401);
    }
}