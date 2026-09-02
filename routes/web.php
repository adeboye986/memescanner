<?php

use App\Http\Controllers\ClosePaperTradeController;
use App\Http\Controllers\DashboardActionController;
use App\Http\Controllers\PaperStrategySettingController;
use App\Http\Controllers\PaperTradingDashboardController;
use App\Http\Controllers\SystemActivityController;
use App\Http\Controllers\TradeHistoryController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', PaperTradingDashboardController::class)->name('dashboard');
Route::post('/dashboard/paper-strategy', PaperStrategySettingController::class)
    ->name('dashboard.paper-strategy.update');
Route::post('/paper-trades/{position}/close', ClosePaperTradeController::class)
    ->name('paper-trades.close');
Route::post('/dashboard/actions/{action}', DashboardActionController::class)
    ->name('dashboard.actions.store');
Route::get('/dashboard/activity', SystemActivityController::class)
    ->name('dashboard.activity');
Route::get('/trades', TradeHistoryController::class)->name('trades.index');
