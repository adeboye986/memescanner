<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateApplicationSettingsRequest;
use App\Services\ApplicationSettingsService;
use App\Services\PaperStrategyService;
use App\Services\SettingsAuditService;
use Illuminate\Http\RedirectResponse;

class UpdateSettingsController extends Controller
{
    public function __invoke(UpdateApplicationSettingsRequest $request, ApplicationSettingsService $settings, PaperStrategyService $strategies, SettingsAuditService $audits): RedirectResponse
    {
        $validated = $request->validated();
        $oldStrategy = $strategies->forNewPosition();
        $settings->update([
            'general.application_name' => $validated['application_name'],
            'trading.execution_mode' => $validated['execution_mode'],
            'trading.entry_mode' => $validated['entry_mode'],
            'scanner.max_chase_percent' => $validated['max_chase_percent'],
            'telegram.enabled' => $request->boolean('telegram_enabled'),
            'telegram.bot_token' => $validated['telegram_bot_token'] ?? null,
            'telegram.chat_id' => $validated['telegram_chat_id'] ?? null,
            'market_data.birdeye_api_key' => $validated['birdeye_api_key'] ?? null,
            'blockchain.solana_rpc_url' => $validated['solana_rpc_url'] ?? null,
            'tracker.snapshot_seconds' => $validated['tracker_snapshot_seconds'],
            'risk.kill_switch' => $request->boolean('kill_switch'),
            'risk.max_trade_amount' => $validated['max_trade_amount'],
            'risk.max_open_positions' => $validated['max_open_positions'],
            'risk.max_daily_loss' => $validated['max_daily_loss'],
            'risk.max_slippage_percent' => $validated['max_slippage_percent'],
            'risk.minimum_wallet_reserve' => $validated['minimum_wallet_reserve'],
            'risk.trade_cooldown_seconds' => $validated['trade_cooldown_seconds'],
        ], $request->user());
        $strategies->updateGlobal([
            'stop_loss_percent' => $validated['stop_loss_percent'],
            'protection_level_1_percent' => $validated['protection_level_1_percent'],
            'protection_level_2_percent' => $validated['protection_level_2_percent'],
        ]);
        $audits->record(
            'strategy.paper',
            sprintf('%s/%s/%s', $oldStrategy['stop_loss_percent'], $oldStrategy['protection_level_1_percent'], $oldStrategy['protection_level_2_percent']),
            sprintf('%s/%s/%s', $validated['stop_loss_percent'], $validated['protection_level_1_percent'], $validated['protection_level_2_percent']),
            $request->user(),
        );

        $message = $validated['execution_mode'] === 'live'
            ? 'Settings saved. LIVE EXECUTION IS NOT ENABLED; all live orders remain blocked.'
            : 'Settings saved successfully.';

        return to_route('settings.index')->with($validated['execution_mode'] === 'live' ? 'warning' : 'success', $message);
    }
}
