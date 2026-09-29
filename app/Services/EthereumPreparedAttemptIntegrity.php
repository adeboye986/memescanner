<?php

namespace App\Services;

use App\Enums\EntryMode;
use App\Exceptions\EthereumPreparationException;
use App\Models\EthereumSwapAttempt;
use App\Models\TradeOpportunity;
use Illuminate\Contracts\Encryption\DecryptException;
use JsonException;

class EthereumPreparedAttemptIntegrity
{
    public const ENGINE_ORIGIN = 'trading_engine_live_preparation';

    private const NETWORK_ID = 'eip155:1';

    private const VERSION = 1;

    public function seal(TradeOpportunity $opportunity, EthereumSwapAttempt $attempt): EthereumSwapAttempt
    {
        if (! $this->requiresEngineBinding($opportunity, $attempt)) {
            return $attempt;
        }

        $expected = $this->digest($opportunity, $attempt);
        if ($attempt->preparation_binding_sha256 !== null) {
            if (! hash_equals($attempt->preparation_binding_sha256, $expected)) {
                throw $this->conflict();
            }

            return $attempt;
        }

        $attempt->update(['preparation_binding_sha256' => $expected]);

        return $attempt;
    }

    public function assertValid(TradeOpportunity $opportunity, EthereumSwapAttempt $attempt): void
    {
        if (! $this->requiresEngineBinding($opportunity, $attempt)) {
            return;
        }

        if (! is_string($attempt->preparation_binding_sha256)
            || preg_match('/^[a-f0-9]{64}$/D', $attempt->preparation_binding_sha256) !== 1
            || ! hash_equals($attempt->preparation_binding_sha256, $this->digest($opportunity, $attempt))) {
            throw $this->conflict();
        }
    }

    public function assertHumanHandoff(EthereumSwapAttempt $attempt): void
    {
        if ($this->isEnginePrepared($attempt)
            && (! $attempt->signing_requested_at || ! $attempt->signing_armed_at || ! $attempt->signing_claim_hash)) {
            throw new EthereumPreparationException('Explicit wallet confirmation is required before transaction reporting.', 409);
        }
    }

    public function isEnginePrepared(EthereumSwapAttempt $attempt): bool
    {
        return $attempt->preparation_origin === self::ENGINE_ORIGIN;
    }

    private function requiresEngineBinding(TradeOpportunity $opportunity, EthereumSwapAttempt $attempt): bool
    {
        $engineOpportunity = $opportunity->entry_mode === EntryMode::Auto
            && data_get($opportunity->execution_data, 'source') === EthereumOpportunityExecutionPolicy::ENGINE_AUTO_SOURCE
            && is_string(data_get($opportunity->execution_data, 'engine_evaluation_id'))
            && is_string(data_get($opportunity->execution_data, 'engine_opportunity_id'));
        $engineAttempt = $this->isEnginePrepared($attempt);

        if ($engineOpportunity !== $engineAttempt) {
            throw $this->conflict();
        }

        return $engineAttempt;
    }

    private function digest(TradeOpportunity $opportunity, EthereumSwapAttempt $attempt): string
    {
        try {
            $payload = $attempt->transaction_payload;
        } catch (DecryptException) {
            throw $this->conflict();
        }

        if (! is_array($payload) || $payload === [] || ! $attempt->expires_at) {
            throw $this->conflict();
        }

        try {
            $encoded = json_encode($this->canonicalize([
                'version' => self::VERSION,
                'origin' => $attempt->preparation_origin,
                'engine_source' => data_get($opportunity->execution_data, 'source'),
                'engine_evaluation_id' => data_get($opportunity->execution_data, 'engine_evaluation_id'),
                'engine_opportunity_id' => data_get($opportunity->execution_data, 'engine_opportunity_id'),
                'opportunity_chain' => $opportunity->chain->value,
                'attempt_id' => $attempt->getKey(),
                'trade_opportunity_id' => $attempt->trade_opportunity_id,
                'user_id' => $attempt->user_id,
                'connected_wallet_id' => $attempt->connected_wallet_id,
                'wallet_address' => $attempt->wallet_address,
                'network_id' => self::NETWORK_ID,
                'buy_token' => $attempt->buy_token,
                'sell_amount_wei' => $attempt->sell_amount_wei,
                'slippage_bps' => (int) $attempt->slippage_bps,
                'quote_id' => $attempt->quote_id,
                'transaction_payload' => $payload,
                'expires_at' => $attempt->expires_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw $this->conflict();
        }

        return hash('sha256', $encoded);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    private function conflict(): EthereumPreparationException
    {
        return new EthereumPreparationException('The prepared Ethereum attempt failed its integrity check.', 409);
    }
}
