<?php

use App\Http\Controllers\AccountPasswordController;
use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\ApproveOpportunityController;
use App\Http\Controllers\ClosePaperTradeController;
use App\Http\Controllers\DashboardActionController;
use App\Http\Controllers\EmailVerificationNotificationController;
use App\Http\Controllers\EmailVerificationPromptController;
use App\Http\Controllers\IgnoreOpportunityController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\NewPasswordController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OpportunityController;
use App\Http\Controllers\PaperStrategySettingController;
use App\Http\Controllers\PaperTradingDashboardController;
use App\Http\Controllers\PasswordResetLinkController;
use App\Http\Controllers\RegisteredUserController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SystemActivityController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\TestIntegrationController;
use App\Http\Controllers\TradeHistoryController;
use App\Http\Controllers\UpdateSettingsController;
use App\Http\Controllers\UserTelegramBotController;
use App\Http\Controllers\UserTradingPreferenceController;
use App\Http\Controllers\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', PaperTradingDashboardController::class)->middleware('auth')->name('dashboard');
Route::post('/dashboard/paper-strategy', PaperStrategySettingController::class)
    ->middleware('auth')
    ->name('dashboard.paper-strategy.update');
Route::put('/dashboard/trading-preferences', UserTradingPreferenceController::class)->middleware('auth')->name('dashboard.trading-preferences.update');
Route::post('/paper-trades/{position}/close', ClosePaperTradeController::class)
    ->middleware('auth')
    ->name('paper-trades.close');
Route::post('/dashboard/actions/{action}', DashboardActionController::class)
    ->middleware(['auth', 'customer.verified'])
    ->name('dashboard.actions.store');
Route::get('/dashboard/activity', SystemActivityController::class)
    ->middleware('auth')
    ->name('dashboard.activity');
Route::get('/trades', TradeHistoryController::class)->middleware('auth')->name('trades.index');
Route::post('/telegram/webhook/{publicId}', TelegramWebhookController::class)->where('publicId', '[A-Za-z0-9]{32}')->middleware('throttle:telegram-webhook')->name('telegram.user-webhook');
Route::post('/telegram/webhook', TelegramWebhookController::class)->middleware('throttle:telegram-webhook')->name('telegram.webhook');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:registration')->name('register.store');
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.update');
});
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/verify-email', EmailVerificationPromptController::class)->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', EmailVerificationNotificationController::class)->middleware('throttle:6,1')->name('verification.send');
    Route::get('/onboarding', OnboardingController::class)->name('onboarding');
    Route::get('/account', [AccountSettingsController::class, 'edit'])->name('account.edit');
    Route::put('/account', [AccountSettingsController::class, 'update'])->name('account.update');
    Route::put('/account/password', AccountPasswordController::class)->name('account.password.update');
});

Route::middleware('auth')->prefix('settings/telegram')->name('telegram.')->group(function (): void {
    Route::get('/', [UserTelegramBotController::class, 'show'])->name('settings');
    Route::post('/link', [UserTelegramBotController::class, 'link'])->middleware('customer.verified')->name('link');
    Route::delete('/link', [UserTelegramBotController::class, 'unlink'])->name('unlink');
});

Route::middleware(['auth', 'can:manage-settings'])->prefix('settings')->name('settings.')->group(function (): void {
    Route::get('/', SettingsController::class)->name('index');
    Route::put('/', UpdateSettingsController::class)->name('update');
    Route::post('/test/{integration}', TestIntegrationController::class)->name('test');
});

Route::middleware('auth')->prefix('opportunities')->name('opportunities.')->group(function (): void {
    Route::get('/', [OpportunityController::class, 'index'])->name('index');
    Route::get('/{opportunity}', [OpportunityController::class, 'show'])->name('show');
    Route::post('/{opportunity}/approve', ApproveOpportunityController::class)->middleware('customer.verified')->name('approve');
    Route::post('/{opportunity}/ignore', IgnoreOpportunityController::class)->name('ignore');
});
