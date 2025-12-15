<?php

use Illuminate\Http\Request;
use App\Http\Controllers\Api\DltController;
use App\Http\Controllers\Api\SsqController;
use App\Http\Controllers\Api\LotterySettingApiController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/dlt/recommend', [DltController::class, 'recommend']);
Route::get('/dlt/download', [DltController::class, 'download']);

Route::get('/ssq/recommend', [SsqController::class, 'recommend']);
Route::get('/lottery/settings', [LotterySettingApiController::class, 'index']);
Route::get('/current-issue', [LotterySettingApiController::class, 'currentIssue']);
