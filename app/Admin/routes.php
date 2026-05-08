<?php

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Dcat\Admin\Admin;

Admin::routes();

Route::group([
    'prefix'     => config('admin.route.prefix'),
    'namespace'  => config('admin.route.namespace'),
    'middleware' => config('admin.route.middleware'),
], function (Router $router) {

    $router->get('/', 'HomeController@index');

    // 彩票基础设置
    $router->resource('lotto-setting', 'LotterySettingController');

    // 历史开奖记录
    $router->resource('dlt-history', 'DltLottoHistoryController');
    $router->resource('ssq-history', 'SsqLottoHistoryController');

    // --- 新增：用户机选记录 ---
    // 统一指向 UserLottoRecordController
    // 具体的“大乐透/双色球”和“单式/复式”区分，通过菜单路径传参实现
    $router->resource('lotto-records/records', 'UserLottoRecordController');

    //用户列表
    $router->resource('users', 'UserController');

});