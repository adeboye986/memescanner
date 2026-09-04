<?php

namespace App\Services;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\PaperPosition;
use App\Models\TradeOpportunity;
use App\Models\User;
use Throwable;

class TradeOpportunityService
{
    public function __construct(
        private ApplicationSettingsService $settings,
        private EntryPolicy $policy,
        private UserTradingPreferenceService $preferences,
    ) {}

    /** @param array<string, mixed> $data
     * @return array{opportunity: TradeOpportunity, position: ?PaperPosition}
     */
    public function qualify(array $data, ?User $requestingUser = null): array
    {
        if ($requestingUser) {
            return $this->createForUser($data, $requestingUser);
        }

        $users = User::query()->get();

        if ($users->isEmpty()) {
            return $this->createForUser($data, null);
        }

        $results = collect();
        $firstFailure = null;

        foreach ($users as $user) {
            try {
                $results->push($this->createForUser($data, $user));
            } catch (Throwable $exception) {
                report($exception);
                $firstFailure ??= $exception;
            }
        }

        if ($results->isEmpty() && $firstFailure) {
            throw $firstFailure;
        }

        return $results->first();
    }

    /** @param array<string, mixed> $data
     * @return array{opportunity: TradeOpportunity, position: ?PaperPosition}
     */
    private function createForUser(array $data, ?User $user): array
    {
        $chain = Chain::fromInput($data['chain'] ?? 'solana');
        $scanner = (string) ($data['scanner'] ?? 'unknown');
        $discoveryKey = (string) ($data['discovery_key'] ?? hash('sha256', implode('|', [$chain->value, $scanner, strtolower((string) $data['address']), (string) ($data['discovery_market_cap'] ?? '')])));
        $preference = $user ? $this->preferences->forUser($user) : null;
        $opportunity = TradeOpportunity::query()->firstOrCreate([
            'user_id' => $user?->id,
            'discovery_key' => $discoveryKey,
        ], [
            'chain' => $chain,
            'address' => $data['address'],
            'symbol' => $data['symbol'] ?? null,
            'name' => $data['name'] ?? null,
            'scanner' => $scanner,
            'status' => TradeOpportunityStatus::Qualified,
            'execution_mode' => $preference?->execution_mode ?? ExecutionMode::from((string) $this->settings->get('trading.execution_mode')),
            'entry_mode' => $preference?->entry_mode ?? EntryMode::from((string) $this->settings->get('trading.entry_mode')),
            'pair_address' => data_get($data, 'meta.pair_address'),
            'price' => $data['entry_price'] ?? null,
            'market_cap' => $data['entry_market_cap'] ?? null,
            'liquidity' => $data['entry_liquidity'] ?? null,
            'volume' => $data['volume'] ?? null,
            'qualification_data' => [
                'discovery_market_cap' => $data['discovery_market_cap'] ?? null,
                'move_since_discovery_percent' => $data['move_since_discovery_percent'] ?? null,
                'send_notification' => $data['send_notification'] ?? true,
                'meta' => $data['meta'] ?? [],
            ],
            'security_data' => $data['security_data'] ?? null,
            'qualified_at' => now(),
        ]);

        if (! $opportunity->wasRecentlyCreated) {
            return ['opportunity' => $opportunity, 'position' => $opportunity->paperPosition];
        }

        return ['opportunity' => $opportunity, 'position' => $this->policy->apply($opportunity)];
    }
}
