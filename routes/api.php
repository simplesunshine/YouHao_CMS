<?php

use Illuminate\Http\Request;
use App\Http\Controllers\Api\DltController;
use App\Http\Controllers\Api\SsqController;
use App\Http\Controllers\Api\LotterySettingApiController;
use App\Http\Controllers\Api\PreferenceController;
use App\Http\Controllers\Api\OpenResultController;
use App\Http\Controllers\Api\NewsController;

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

Route::post('/dlt/recommend', [DltController::class, 'recommend']);
Route::get('/dlt/download', [DltController::class, 'download']);

//双色球统一机选接口
Route::post('/ssq/pick', [SsqController::class, 'pick']);
Route::get('/ssq/download', [SsqController::class, 'download']);



//用户查询自选号码是否存在机选库
Route::post('lotto/check-front-exists', 'Api\LottoCheckController@checkFrontExists')->middleware('throttle:30,1');


Route::get('/lottery/settings', [LotterySettingApiController::class, 'index']);
Route::get('/current-issue', [LotterySettingApiController::class, 'currentIssue']);

Route::get('/open-result/latest', [OpenResultController::class, 'latest']);

Route::get('/ssq-history', [OpenResultController::class, 'ssqHistory']);
Route::get('/dlt-history', [OpenResultController::class, 'dltHistory']);


Route::get('/news/ssq', [NewsController::class, 'ssq']);
Route::get('/news/dlt', [NewsController::class, 'dlt']);








