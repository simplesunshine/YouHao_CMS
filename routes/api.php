<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// 引入所有控制器
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DltController;
use App\Http\Controllers\Api\SsqController;
use App\Http\Controllers\Api\LotterySettingApiController;
use App\Http\Controllers\Api\OpenResultController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\LottoAnalysisController;
use App\Http\Controllers\Api\SsqFushiController;
use App\Http\Controllers\Api\DltFushiController;
use App\Http\Controllers\Api\LottoRecordController;
use App\Http\Controllers\Api\LotteryStrategyController;
use App\Http\Controllers\Api\DltReLiTuController;
use App\Http\Controllers\Api\KillHistoryController;



/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// --- 1. 开放接口 (无需登录即可访问) ---

// 用户认证
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// 获取机选思路数据
Route::get('/strategy/{type}', [LotteryStrategyController::class, 'getStrategyData']);

// 基础数据与设置
Route::get('/lottery/settings', [LotterySettingApiController::class, 'index']);
Route::get('/current-issue', [LotterySettingApiController::class, 'currentIssue']);
Route::get('/open-result/latest', [OpenResultController::class, 'latest']);

// 使用 {type} 占位符，匹配 /api/dashboard/ssq 或 /api/dashboard/dlt
Route::get('/dashboard/{type}', [DashboardController::class, 'index'])
    ->where('type', 'ssq|dlt'); // 这里的正则约束能防止非法参数进入后端逻辑

// 历史开奖与统计 (通常开放给用户看，无需登录)
Route::get('/ssq-history', [OpenResultController::class, 'ssqHistory']);
Route::get('/ssq-biaoji-history', [OpenResultController::class, 'ssqBiaojiHistory']);
Route::get('/dlt-history', [OpenResultController::class, 'dltHistory']);
Route::get('/dlt-biaoji-history', [OpenResultController::class, 'dltBiaojiHistory']);

Route::get('/ssq/lastIssue', [SsqController::class, 'lastIssue']);
Route::get('/ssq/number-distribution', [SsqController::class, 'numberDistribution']);
// 获取两码组合
Route::get('/ssq/pair-stats', [SsqController::class, 'pairStats']);
// 获取高频两码组合
Route::get('/ssq/hot-pairs', [SsqController::class, 'getHotPairs']);

Route::get('/dlt/lastIssue', [DltController::class, 'lastIssue']);
Route::get('/dlt/number-distribution', [DltController::class, 'numberDistribution']);
Route::get('/dlt/back-combo-stats', [DltController::class, 'backComboStats']);
Route::get('/dlt/pair-stats', [DltController::class, 'pairStats']);

//新加和值遗漏
Route::get('/ssq/sum_interval', [SsqController::class, 'sum_interval']);
Route::get('/dlt/sum_interval', [DltController::class, 'sum_interval']);

Route::get('/ssq/edge-history', [SsqController::class, 'edgeHistory']);
Route::get('/dlt/edge-history', [DltController::class, 'edgeHistory']);

Route::get('/ssq/hot_number', [SsqController::class, 'hotNumber']);
Route::get('/dlt/hot_number', [DltController::class, 'hotNumber']);

Route::get('/dlt/relitu', [DltReLiTuController::class, 'index']);

// =================================================================
// 【新增】系统自动杀号历史战绩与统计看板接口
// =================================================================
// 1. 获取杀号战绩的顶部统计看板数据（累计期数、准确率、当前连对）
Route::get('/kill-history/stats', [KillHistoryController::class, 'getKillStats']);

// 2. 获取杀号逐期对错的分页历史列表
Route::get('/kill-history/list', [KillHistoryController::class, 'getKillList']);



// --- 2. 保护接口 (必须登录后才能访问) ---

Route::middleware(['auth:sanctum', 'update.last.login'])->group(function (){
    
    // 用户个人信息
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/logout', [AuthController::class, 'logout']);

    // --- 新增：用户机选历史记录 ---
    Route::get('/user/lotto-records', [LottoRecordController::class, 'index']);

    // --- 双色球 (SSQ) 核心机选 ---
    Route::post('/ssq/pick', [SsqController::class, 'pick']);
    Route::get('/ssq/download', [SsqController::class, 'download']);

    Route::get('/ssq/score', [SsqController::class, 'score']); // 评分详情接口
    Route::get('/ssq/check-omission', [SsqController::class, 'checkOmission']); // 【新加】红球6-11遗漏值过滤形态接口
    
    Route::get('/dlt/score', [DltController::class, 'score']); // 大乐透评分详情
    Route::get('/dlt/check-omission', [DltController::class, 'checkOmission']); // 【新加】红球5-9遗漏值过滤形态接口

    Route::post('/ssq/filter-dadan', [SsqController::class, 'filterDadan']); 
    Route::post('/dlt/filter-dadan', [DltController::class, 'filterDadan']); 

    
    // SSQ 复式相关
    Route::get('/ssq/fushi/normal_fushi', [SsqFushiController::class, 'normalFushi']);
    Route::get('/ssq/fushi/dantuo_fushi', [SsqFushiController::class, 'dantuoFushi']);
    Route::get('/ssq/fushi/fixed_kill', [SsqFushiController::class, 'fixedKillFushi']);
    Route::get('/ssq/fushi/kill_fushi', [SsqFushiController::class, 'userKillFushi']);

    // --- 大乐透 (DLT) 核心机选 ---
    Route::post('/dlt/pick', [DltController::class, 'pick']);
    Route::post('/dlt/recommend', [DltController::class, 'recommend']);
    Route::get('/dlt/download', [DltController::class, 'download']);

    Route::get('/dlt/fushi/normal_fushi', [DltFushiController::class, 'normalFushi']);
    Route::get('/dlt/fushi/dantuo_fushi', [DltFushiController::class, 'dantuoFushi']);

    // 自选号码查询和评分
    Route::post('lotto/analysis', [LottoAnalysisController::class, 'index']);
    Route::post('lotto/score', [LottoAnalysisController::class, 'score']);

});