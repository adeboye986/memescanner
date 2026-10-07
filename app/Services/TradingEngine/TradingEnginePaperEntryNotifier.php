<?php

namespace App\Services\TradingEngine;

use App\Models\TradingEnginePaperPositionProjection;
use App\Models\User;
use App\Services\UserTelegramNotificationService;
use Throwable;

class TradingEnginePaperEntryNotifier
{
    public function __construct(private UserTelegramNotificationService $notifications) {}

    public function send(TradingEnginePaperPositionProjection $position): void
    {
        $opportunity = $position->opportunity;
        $user = User::query()->find($position->user_id);

        if ($user === null
            || $opportunity === null
            || ! (bool) data_get($opportunity->qualification_data, 'send_notification', true)) {
            return;
        }

        try {
            $message = "🟢🟢🟢 <b>ENGINE PAPER BUY EXECUTED</b> 🟢🟢🟢\n\n".
                '<b>Symbol:</b> '.($position->symbol ?: 'Unknown token')."\n".
                '<b>Chain:</b> SOLANA'."\n\n".
                '<b>Financial authority:</b> TypeScript trading engine'."\n".
                '<b>PAPER TRADE — NO REAL FUNDS USED</b>';

            $this->notifications->send($user, $message);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
