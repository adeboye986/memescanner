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
        return UserTradingPreference::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'execution_mode' => $user->is_admin
                    ? ExecutionMode::tryFrom((string) $this->platformSettings->get('trading.execution_mode')) ?? ExecutionMode::Paper
                    : ExecutionMode::Paper,
                'entry_mode' => $user->is_admin
                    ? EntryMode::tryFrom((string) $this->platformSettings->get('trading.entry_mode')) ?? EntryMode::Signal
                    : EntryMode::Signal,
                'trading_enabled' => true,
            ],
        );
    }

    public function update(User $user, ExecutionMode $executionMode, EntryMode $entryMode): UserTradingPreference
    {
        if ($executionMode !== ExecutionMode::Paper) {
            throw new \DomainException('Live execution is not enabled yet.');
        }

        $preference = $this->forUser($user);
        $preference->update(['execution_mode' => $executionMode, 'entry_mode' => $entryMode]);

        return $preference->fresh();
    }
}
