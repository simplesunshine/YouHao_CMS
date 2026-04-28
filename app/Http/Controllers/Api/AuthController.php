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

        // 生成 Token (使用 Sanctum)
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'profile_picture' => $user->profile_picture,
            ],
            'token' => $token
        ]);
    }

    public function logout(Request $request)
    {
        // 获取当前通过 Token 认证的用户
        $user = $request->user();

        if ($user) {
            // 将数据库中的 api_token 置空，让该 Token 立即失效
            $user->api_token = null;
            $user->save();

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