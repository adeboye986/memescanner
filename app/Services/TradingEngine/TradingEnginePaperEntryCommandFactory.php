<?php

namespace App\Services\TradingEngine;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Models\ApplicationSetting;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityLink;
use App\Models\UserTradingPreference;
use App\Services\ApplicationSettingsService;
use App\Services\PaperStrategyService;
use Carbon\CarbonImmutable;
use LogicException;

class TradingEnginePaperEntryCommandFactory
{
    private const SOLANA_MAINNET_ID = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

    public function __construct(
        private CanonicalDecimal $decimals,
        private PaperStrategyService $strategies,
        private ApplicationSettingsService $settings,
    ) {}

    /**
     * @return array{payload: array<string, mixed>, idempotency_key: string}
     */
    public function make(
        TradeOpportunity $opportunity,
        TradingEngineOpportunityLink $link,
        TradingEngineOpportunityEvaluation $evaluation,
    ): array {
        if (! $opportunity->exists
            || $opportunity->chain !== Chain::Solana
            || $opportunity->user_id === null
            || $opportunity->execution_mode !== ExecutionMode::Paper
            || $opportunity->entry_mode !== EntryMode::Auto
            || $link->trade_opportunity_id !== $opportunity->getKey()
            || $evaluation->opportunity_link_id !== $link->getKey()
            || $evaluation->trade_opportunity_id !== $opportunity->getKey()
            || $evaluation->outcome !== 'passed') {
            throw new LogicException('The opportunity is not eligible for an engine PAPER entry intent.');
        }

        $user = $opportunity->user;
        $preference = UserTradingPreference::query()->where('user_id', $opportunity->user_id)->first();

        if ($user === null || $preference === null
            || $preference->execution_mode !== ExecutionMode::Paper
            || $preference->entry_mode !== EntryMode::Auto
            || ! $preference->trading_enabled
            || $this->settings->get('risk.kill_switch')) {
            throw new LogicException('The current control-plane authority does not permit engine PAPER entry.');
        }

        $notional = $this->requiredDecimal(config('services.trading.paper_trade_size_sol', 0.10));
        $strategy = $this->strategies->forUser($user);
        $createdAt = CarbonImmutable::now('UTC');
        $expiresAt = $createdAt->addSeconds(300);
        $payload = [
            'schema_version' => 1,
            'source' => [
                'system' => 'meme-scanner-laravel',
                'trade_opportunity_id' => (string) $opportunity->getKey(),
                'engine_opportunity_id' => $link->engine_opportunity_id,
                'evaluation_id' => $evaluation->evaluation_id,
                'evaluation_result_sha256' => $evaluation->result_sha256,
            ],
            'subject' => [
                'control_plane_user_id' => (string) $opportunity->user_id,
            ],
            'network' => [
                'id' => self::SOLANA_MAINNET_ID,
                'native_currency' => 'SOL',
            ],
            'asset' => array_filter([
                'address' => $opportunity->address,
                'symbol' => $opportunity->symbol,
            ], fn (mixed $value): bool => is_string($value) && $value !== ''),
            'entry' => array_filter([
                'requested_notional_native' => $notional,
                'market_cap_usd' => $this->requiredDecimal($opportunity->getRawOriginal('market_cap')),
                'price_usd' => $this->requiredDecimal($opportunity->getRawOriginal('price')),
                'liquidity_usd' => $this->decimals->normalize($opportunity->getRawOriginal('liquidity')),
                'intent_created_at' => $createdAt->format('Y-m-d\TH:i:s.v\Z'),
                'expires_at' => $expiresAt->format('Y-m-d\TH:i:s.v\Z'),
            ], fn (mixed $value): bool => $value !== null),
            'authority' => [
                'execution_mode' => 'paper',
                'entry_mode' => 'auto',
                'trading_enabled' => true,
                'preference_version' => $this->preferenceVersion($preference),
                'kill_switch_engaged' => false,
                'kill_switch_version' => $this->killSwitchVersion(),
                'strategy' => [
                    'stop_loss_percent' => $this->requiredDecimal($strategy['stop_loss_percent']),
                    'protection_level_1_percent' => $this->requiredDecimal($strategy['protection_level_1_percent']),
                    'protection_level_2_percent' => $this->requiredDecimal($strategy['protection_level_2_percent']),
                ],
                'risk' => [
                    'trade_size_native' => $notional,
                    'source' => 'laravel-control-plane',
                ],
                'effective_policy' => [
                    'key' => 'engine-paper-entry',
                    'version' => 1,
                ],
            ],
        ];

        return [
            'payload' => $payload,
            'idempotency_key' => 'paper:entry:laravel:'.$opportunity->getKey().':v1',
        ];
    }

    private function requiredDecimal(mixed $value): string
    {
        $normalized = $this->decimals->normalize($value);

        if ($normalized === null || $normalized === '0') {
            throw new LogicException('A positive canonical PAPER entry decimal is required.');
        }

        return $normalized;
    }

    private function preferenceVersion(UserTradingPreference $preference): string
    {
        return 'user-trading-preference:'.$preference->getKey().':'.($preference->updated_at?->utc()->format('YmdHis.u') ?? 'unversioned');
    }

    private function killSwitchVersion(): string
    {
        $setting = ApplicationSetting::query()
            ->where('scope', 'system')
            ->where('owner_id', 0)
            ->where('group', 'risk')
            ->where('key', 'risk.kill_switch')
            ->first();

        return $setting === null
            ? 'risk.kill_switch:default:false:v1'
            : 'risk.kill_switch:'.$setting->getKey().':'.$setting->updated_at->utc()->format('YmdHis.u');
    }
}
