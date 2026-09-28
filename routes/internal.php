<?php

use App\Http\Controllers\TradingEngineWebhookController;
use App\Http\Middleware\EnforceTradingEngineWebhookBodyLimit;
use Illuminate\Support\Facades\Route;

Route::post('/internal/trading-engine/events', TradingEngineWebhookController::class)
    ->middleware([EnforceTradingEngineWebhookBodyLimit::class, 'throttle:trading-engine-webhook'])
    ->name('internal.trading-engine.events');
