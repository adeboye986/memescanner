<?php

namespace App\Services;

use App\Chain;
use App\Models\TelegramIdentity;
use App\Models\User;

class OnboardingStatusService
{
    public function __construct(private ApplicationSettingsService $settings) {}

    /** @return array{account: bool, email: bool, paper_account: bool, telegram_bot: bool, telegram_link: bool, preferences: bool, strategy: bool, ready: bool} */
    public function forUser(User $user): array
    {
        $user->loadMissing(['tradingPreference', 'paperWallets']);
        $walletChains = $user->paperWallets->pluck('chain')->map(fn (mixed $chain): string => $chain instanceof Chain ? $chain->value : (string) $chain);
        $platformBotAvailable = (bool) $this->settings->get('telegram.enabled')
            && (bool) $this->settings->getSecret('telegram.bot_token')
            && (bool) $this->settings->getSecret('telegram.webhook_secret')
            && trim((string) $this->settings->get('telegram.bot_username')) !== '';
        $identityLinked = TelegramIdentity::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereNull('user_telegram_bot_id')
            ->exists();

        $status = [
            'account' => $user->exists,
            'email' => $user->hasVerifiedEmail(),
            'paper_account' => $user->tradingPreference !== null && collect(Chain::cases())->every(fn (Chain $chain): bool => $walletChains->contains($chain->value)),
            'telegram_bot' => $platformBotAvailable,
            'telegram_link' => $identityLinked,
            'preferences' => $user->tradingPreference !== null,
            'strategy' => $user->paperStrategySettings()->where('name', 'default')->exists(),
        ];
        $status['ready'] = ! collect($status)->containsStrict(false);

        return $status;
    }
}
