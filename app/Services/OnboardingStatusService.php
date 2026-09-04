<?php

namespace App\Services;

use App\Chain;
use App\Models\User;

class OnboardingStatusService
{
    /** @return array{account: bool, email: bool, paper_account: bool, telegram_bot: bool, telegram_link: bool, preferences: bool, strategy: bool, ready: bool} */
    public function forUser(User $user): array
    {
        $user->loadMissing(['tradingPreference', 'paperWallets', 'telegramBot.identity']);
        $bot = $user->telegramBot;
        $walletChains = $user->paperWallets->pluck('chain')->map(fn (mixed $chain): string => $chain instanceof Chain ? $chain->value : (string) $chain);

        $status = [
            'account' => $user->exists,
            'email' => $user->hasVerifiedEmail(),
            'paper_account' => $user->tradingPreference !== null && collect(Chain::cases())->every(fn (Chain $chain): bool => $walletChains->contains($chain->value)),
            'telegram_bot' => $bot !== null && $bot->enabled && $bot->last_verified_at !== null && $bot->webhook_configured_at !== null,
            'telegram_link' => $bot?->identity?->status === 'active',
            'preferences' => $user->tradingPreference !== null,
            'strategy' => $user->paperStrategySettings()->where('name', 'default')->exists(),
        ];
        $status['ready'] = ! collect($status)->containsStrict(false);

        return $status;
    }
}
