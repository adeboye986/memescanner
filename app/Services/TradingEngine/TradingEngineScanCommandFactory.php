<?php

namespace App\Services\TradingEngine;

use App\Chain;
use App\Models\SystemActivity;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use LogicException;

class TradingEngineScanCommandFactory
{
    private const ETHEREUM_MAINNET_ID = 'eip155:1';

    private const SOLANA_MAINNET_ID = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

    public function __construct(private ApplicationSettingsService $settings) {}

    /** @return array{idempotency_key: string, entry_mode: string, payload: array<string, mixed>} */
    public function make(SystemActivity $activity, User $user): array
    {
        $scanner = match ($activity->action) {
            'token-scan' => 'new-token',
            'momentum-scan' => 'momentum',
            default => throw new LogicException('The dashboard activity is not a token scan.'),
        };
        $chain = $activity->chain;

        if (! $chain instanceof Chain || $activity->user_id !== $user->getKey()) {
            throw new LogicException('The scan activity ownership boundary is invalid.');
        }

        $preference = $user->tradingPreference()->firstOrFail();

        return [
            'idempotency_key' => 'token-scan:laravel:'.$activity->getKey().':v1',
            'entry_mode' => $preference->entry_mode->value,
            'payload' => [
                'schema_version' => 1,
                'source' => [
                    'system' => 'meme-scanner-laravel',
                    'activity_id' => (string) $activity->getKey(),
                ],
                'subject' => ['control_plane_user_id' => (string) $user->getKey()],
                'scanner' => $scanner,
                'network' => [
                    'id' => $chain === Chain::Solana
                        ? self::SOLANA_MAINNET_ID
                        : self::ETHEREUM_MAINNET_ID,
                ],
                'policy' => [
                    'max_chase_percent' => $this->decimal((float) $this->settings->get('scanner.max_chase_percent')),
                    'momentum_birdeye_budget' => max(0, min(20, (int) config('services.birdeye.momentum_budget', 1))),
                ],
            ],
        ];
    }

    private function decimal(float $value): string
    {
        $formatted = rtrim(rtrim(number_format(max(0, $value), 8, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
