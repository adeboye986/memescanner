<?php

namespace App\Services\TradingEngine;

use App\Enums\ExecutionMode;
use App\Models\User;
use App\Services\UserTradingPreferenceService;

class TradingEngineScannerOwnership
{
    public function __construct(private UserTradingPreferenceService $preferences) {}

    public function routes(User $user, string $action): bool
    {
        if (! in_array($action, ['token-scan', 'momentum-scan'], true)
            || config('services.trading_engine.enabled', false) !== true
            || config('services.trading_engine.scanner_integration_enabled', false) !== true
            || config('services.trading_engine.opportunity_projection_enabled', false) !== true
            || ! in_array($user->getKey(), $this->canaryUserIds(), true)) {
            return false;
        }

        $preference = $this->preferences->forUser($user);

        return $preference->execution_mode === ExecutionMode::Paper
            && $preference->trading_enabled;
    }

    /** @return list<int> */
    private function canaryUserIds(): array
    {
        $configured = config('services.trading_engine.scanner_canary_user_ids', '');

        if (! is_string($configured)) {
            return [];
        }

        return array_values(array_unique(array_map(
            fn (string $id): int => (int) $id,
            array_filter(
                array_map('trim', explode(',', $configured)),
                fn (string $id): bool => preg_match('/^[1-9][0-9]*$/D', $id) === 1,
            ),
        )));
    }
}
