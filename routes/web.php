<?php

use App\Http\Controllers\ApproveOpportunityController;
use App\Http\Controllers\ClosePaperTradeController;
use App\Http\Controllers\DashboardActionController;
use App\Http\Controllers\IgnoreOpportunityController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\OpportunityController;
use App\Http\Controllers\PaperStrategySettingController;
use App\Http\Controllers\PaperTradingDashboardController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SystemActivityController;
use App\Http\Controllers\TelegramAccessController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\TestIntegrationController;
use App\Http\Controllers\TradeHistoryController;
use App\Http\Controllers\UpdateSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', PaperTradingDashboardController::class)->name('dashboard');
Route::post('/dashboard/paper-strategy', PaperStrategySettingController::class)
    ->middleware(['auth', 'can:manage-settings'])
    ->name('dashboard.paper-strategy.update');
Route::post('/paper-trades/{position}/close', ClosePaperTradeController::class)
    ->name('paper-trades.close');
Route::post('/dashboard/actions/{action}', DashboardActionController::class)
    ->name('dashboard.actions.store');
Route::get('/dashboard/activity', SystemActivityController::class)
    ->name('dashboard.activity');
Route::get('/trades', TradeHistoryController::class)->name('trades.index');
Route::post('/telegram/webhook', TelegramWebhookController::class)->middleware('throttle:telegram-webhook')->name('telegram.webhook');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'can:manage-settings'])->prefix('settings')->name('settings.')->group(function (): void {
    Route::get('/', SettingsController::class)->name('index');
    Route::put('/', UpdateSettingsController::class)->name('update');
    Route::post('/test/{integration}', TestIntegrationController::class)->name('test');
    Route::post('/telegram/link', [TelegramAccessController::class, 'store'])->name('telegram.link');
    Route::delete('/telegram/link', [TelegramAccessController::class, 'destroy'])->name('telegram.unlink');
});

Route::middleware(['auth', 'can:manage-settings'])->prefix('opportunities')->name('opportunities.')->group(function (): void {
    Route::get('/', [OpportunityController::class, 'index'])->name('index');
    Route::get('/{opportunity}', [OpportunityController::class, 'show'])->name('show');
    Route::post('/{opportunity}/approve', ApproveOpportunityController::class)->name('approve');
    Route::post('/{opportunity}/ignore', IgnoreOpportunityController::class)->name('ignore');
});
