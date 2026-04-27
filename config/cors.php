<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    */

    // 1. 确保包含 sanctum 的路径（如果你未来使用 Cookie 认证）
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'register'],

    'allowed_methods' => ['*'],

    // 2. 开发环境建议暂时允许所有，或者包含常见的开发端口
    'allowed_origins' => [
        'http://localhost:5173', 
        'http://127.0.0.1:5173', 
        'http://localhost:3000'
    ],

    // 也可以直接用通配符（简单粗暴，但生产环境记得改回来）：
    // 'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // 3. 如果你只是用 Token (Bearer Header)，这里保持 false 没问题
    // 如果你打算用 Cookie 登录，必须改为 true
    'supports_credentials' => true,

];