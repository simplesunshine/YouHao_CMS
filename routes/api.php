<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// 引入所有控制器
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DltController;
use App\Http\Controllers\Api\SsqController;
use App\Http\Controllers\Api\LotterySettingApiController;
use App\Http\Controllers\Api\OpenResultController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\LottoAnalysisController;
use App\Http\Controllers\Api\SsqFushiController;
use App\Http\Controllers\Api\DltFushiController;
use App\Http\Controllers\Api\LottoRecordController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// --- 1. 开放接口 (无需登录即可访问) ---

// 用户认证
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// 基础数据与设置
Route::get('/lottery/settings', [LotterySettingApiController::class, 'index']);
Route::get('/current-issue', [LotterySettingApiController::class, 'currentIssue']);
Route::get('/open-result/latest', [OpenResultController::class, 'latest']);
Route::get('/news/ssq', [NewsController::class, 'ssq']);
Route::get('/news/dlt', [NewsController::class, 'dlt']);

// 历史开奖与统计 (通常开放给用户看，无需登录)
Route::get('/ssq-history', [OpenResultController::class, 'ssqHistory']);
Route::get('/ssq-biaoji-history', [OpenResultController::class, 'ssqBiaojiHistory']);
Route::get('/dlt-history', [OpenResultController::class, 'dltHistory']);
Route::get('/dlt-biaoji-history', [OpenResultController::class, 'dltBiaojiHistory']);

Route::get('/ssq/lastIssue', [SsqController::class, 'lastIssue']);
Route::get('/ssq/number-distribution', [SsqController::class, 'numberDistribution']);
Route::get('/ssq/pair-stats', [SsqController::class, 'pairStats']);

Route::get('/dlt/lastIssue', [DltController::class, 'lastIssue']);
Route::get('/dlt/number-distribution', [DltController::class, 'numberDistribution']);
Route::get('/dlt/back-combo-stats', [DltController::class, 'backComboStats']);
Route::get('/dlt/pair-stats', [DltController::class, 'pairStats']);


// --- 2. 保护接口 (必须登录后才能访问) ---

Route::middleware('auth:sanctum')->group(function () {
    
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

    Route::get('/ssq/score', [SsqController::class, 'score']); // 新加的评分详情
    Route::get('/dlt/score', [DltController::class, 'score']); // 新加的评分详情

    
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

    // 自选号码查询 (既然选号逻辑需登录，查询也放进来)
    Route::post('lotto/analysis', [LottoAnalysisController::class, 'index']);
});