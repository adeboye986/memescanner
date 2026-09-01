<?php

use App\Http\Controllers\ClosePaperTradeController;
use App\Http\Controllers\DashboardActionController;
use App\Http\Controllers\PaperTradingDashboardController;
use App\Http\Controllers\SystemActivityController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', PaperTradingDashboardController::class)->name('dashboard');
Route::post('/paper-trades/{position}/close', ClosePaperTradeController::class)
    ->name('paper-trades.close');
Route::post('/dashboard/actions/{action}', DashboardActionController::class)
    ->name('dashboard.actions.store');
Route::get('/dashboard/activity', SystemActivityController::class)
    ->name('dashboard.activity');
