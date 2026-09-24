<?php

namespace App\Services;

use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Models\User;
use App\Models\UserTradingPreference;

class UserTradingPreferenceService
{
    public function __construct(private ApplicationSettingsService $platformSettings) {}

    public function forUser(User $user): UserTradingPreference
    {
        $executionMode = $user->is_admin
            ? ExecutionMode::tryFrom((string) $this->platformSettings->get('trading.execution_mode')) ?? ExecutionMode::Paper
            : ExecutionMode::Paper;
        $entryMode = $user->is_admin
            ? EntryMode::tryFrom((string) $this->platformSettings->get('trading.entry_mode')) ?? EntryMode::Signal
            : EntryMode::Signal;

        return UserTradingPreference::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'execution_mode' => $executionMode,
                'entry_mode' => $executionMode === ExecutionMode::Live ? EntryMode::Confirm : $entryMode,
                'trading_enabled' => true,
            ],
        );
    }

    public function update(User $user, ExecutionMode $executionMode, EntryMode $entryMode): UserTradingPreference
    {
        if ($executionMode === ExecutionMode::Live && $entryMode !== EntryMode::Confirm) {
            throw new \DomainException('LIVE supports CONFIRM only. Select CONFIRM before switching to LIVE.');
        }

        $preference = $this->forUser($user);
        $preference->newQuery()->whereKey($preference->id)->update(['execution_mode' => $executionMode->value, 'entry_mode' => $entryMode->value]);

        return $preference->fresh();
    }
}
