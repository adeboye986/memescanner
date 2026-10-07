<?php

namespace App\Services\TradingEngine;

use App\Chain;
use App\Jobs\SubmitTradingEnginePaperPosition;
use App\Models\PaperPosition;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineOpportunityLink;
use App\Models\TradingEnginePaperPositionLink;
use Illuminate\Support\Facades\DB;
use Throwable;

class TradingEnginePaperLifecycleEnrollment
{
    private const NETWORK_ID = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

    public function __construct(
        private CanonicalDecimal $decimals,
        private TradingEngineCanonicalJson $canonicalJson,
    ) {}

    public function enroll(PaperPosition $position, TradeOpportunity $opportunity): ?TradingEnginePaperPositionLink
    {
        if (! $this->eligible($position, $opportunity)) {
            return null;
        }

        $engineOpportunity = TradingEngineOpportunityLink::query()
            ->where('trade_opportunity_id', $opportunity->getKey())
            ->first();

        if (! $engineOpportunity instanceof TradingEngineOpportunityLink) {
            return null;
        }

        $payload = $this->payload($position, $opportunity, $engineOpportunity);
        $link = DB::transaction(function () use ($position, $opportunity, $engineOpportunity, $payload): TradingEnginePaperPositionLink {
            return TradingEnginePaperPositionLink::query()->firstOrCreate(
                ['paper_position_id' => $position->getKey()],
                [
                    'trade_opportunity_id' => $opportunity->getKey(),
                    'user_id' => $position->user_id,
                    'engine_opportunity_id' => $engineOpportunity->engine_opportunity_id,
                    'chain' => $position->chain->value,
                    'asset_address' => $position->address,
                    'ownership_state' => 'pending_registration',
                    'next_observation_sequence' => 1,
                    'ownership_snapshot' => [
                        'owner' => 'trading-engine',
                        'authoritative' => true,
                        'enrolled_at' => now()->toIso8601String(),
                        'policy_key' => 'laravel-paper-protection',
                        'policy_version' => 1,
                    ],
                    'registration_payload' => $payload,
                    'registration_payload_sha256' => $this->canonicalJson->hash($payload),
                    'registration_idempotency_key' => 'paper:position:record:laravel:'.$position->getKey().':v1',
                ],
            );
        }, 3);

        $this->dispatchRegistration($link);

        return $link;
    }

    public function ensureRegistration(TradingEnginePaperPositionLink $link): void
    {
        if ($link->ownership_state === 'pending_registration') {
            $this->dispatchRegistration($link);
        }
    }

    public function eligible(PaperPosition $position, TradeOpportunity $opportunity): bool
    {
        return config('services.trading_engine.enabled', false) === true
            && config('services.trading_engine.paper_lifecycle_integration_enabled', false) === true
            && config('services.trading_engine.paper_lifecycle_authoritative_enabled', false) === true
            && $position->wasRecentlyCreated
            && $position->chain === Chain::Solana
            && $position->user_id !== null
            && $opportunity->user_id === $position->user_id
            && (config('services.trading_engine.paper_lifecycle_general_rollout_enabled', false) === true
                || in_array((string) $position->user_id, $this->canaryUserIds(), true)
            );
    }

    /** @return array<int, string> */
    private function canaryUserIds(): array
    {
        $configured = config('services.trading_engine.paper_lifecycle_canary_user_ids', '');

        if (! is_string($configured)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $configured)),
            fn (string $value): bool => preg_match('/^[1-9][0-9]*$/D', $value) === 1,
        )));
    }

    /** @return array<string, mixed> */
    private function payload(
        PaperPosition $position,
        TradeOpportunity $opportunity,
        TradingEngineOpportunityLink $engineOpportunity,
    ): array {
        $strategy = (array) $position->strategy_snapshot;

        return [
            'schema_version' => 1,
            'source' => [
                'system' => 'meme-scanner-laravel',
                'paper_position_id' => (string) $position->getKey(),
                'trade_opportunity_id' => (string) $opportunity->getKey(),
                'engine_opportunity_id' => $engineOpportunity->engine_opportunity_id,
            ],
            'subject' => ['control_plane_user_id' => (string) $position->user_id],
            'network' => ['id' => self::NETWORK_ID],
            'asset' => array_filter([
                'address' => $position->address,
                'symbol' => $position->symbol,
            ], fn (mixed $value): bool => is_string($value) && $value !== ''),
            'entry' => array_filter([
                'initial_investment_native' => $this->requiredDecimal($position->getRawOriginal('initial_investment_sol')),
                'market_cap_usd' => $this->requiredDecimal($position->getRawOriginal('entry_market_cap')),
                'price_usd' => $this->decimals->normalize($position->getRawOriginal('entry_price')),
                'liquidity_usd' => $this->decimals->normalize($position->getRawOriginal('entry_liquidity')),
                'entered_at' => $position->entry_at->toISOString(),
            ], fn (mixed $value): bool => $value !== null),
            'strategy' => [
                'stop_loss_percent' => $this->requiredDecimal($strategy['stop_loss_percent'] ?? null),
                'protection_level_1_percent' => $this->requiredDecimal($strategy['protection_level_1_percent'] ?? null),
                'protection_level_2_percent' => $this->requiredDecimal($strategy['protection_level_2_percent'] ?? null),
            ],
        ];
    }

    private function requiredDecimal(mixed $value): string
    {
        return $this->decimals->normalize($value)
            ?? throw new \LogicException('The PAPER lifecycle value is missing.');
    }

    private function dispatchRegistration(TradingEnginePaperPositionLink $link): void
    {
        try {
            SubmitTradingEnginePaperPosition::dispatch(
                $link->getKey(),
                $link->registration_payload,
                $link->registration_idempotency_key,
            );
        } catch (Throwable) {
            $link->forceFill(['registration_error_code' => 'REGISTRATION_DISPATCH_FAILED'])->save();
        }
    }
}
