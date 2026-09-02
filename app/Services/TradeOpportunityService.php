<?php

namespace App\Services;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\PaperPosition;
use App\Models\TradeOpportunity;

class TradeOpportunityService
{
    public function __construct(
        private ApplicationSettingsService $settings,
        private EntryPolicy $policy,
    ) {}

    /** @param array<string, mixed> $data
     * @return array{opportunity: TradeOpportunity, position: ?PaperPosition}
     */
    public function qualify(array $data): array
    {
        $opportunity = TradeOpportunity::query()->create([
            'chain' => Chain::fromInput($data['chain'] ?? 'solana'),
            'address' => $data['address'],
            'symbol' => $data['symbol'] ?? null,
            'name' => $data['name'] ?? null,
            'scanner' => $data['scanner'] ?? 'unknown',
            'status' => TradeOpportunityStatus::Qualified,
            'execution_mode' => ExecutionMode::from((string) $this->settings->get('trading.execution_mode')),
            'entry_mode' => EntryMode::from((string) $this->settings->get('trading.entry_mode')),
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

        return ['opportunity' => $opportunity, 'position' => $this->policy->apply($opportunity)];
    }
}
