<?php

namespace App\Services;

use App\Chain;
use App\Enums\ExecutionMode;
use App\Exceptions\SolanaPreparationException;
use App\Models\SolanaSwapAttempt;
use App\Models\TradeOpportunity;
use Illuminate\Contracts\Encryption\DecryptException;
use JsonException;

class SolanaPreparedAttemptIntegrity
{
    public const ENGINE_ORIGIN = 'trading_engine_solana_live_preparation';

    public const NETWORK = 'mainnet-beta';

    private const VERSION = 1;

    public function seal(TradeOpportunity $opportunity, SolanaSwapAttempt $attempt): SolanaSwapAttempt
    {
        $this->assertEnginePair($opportunity, $attempt);
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

    public function assertValid(TradeOpportunity $opportunity, SolanaSwapAttempt $attempt): void
    {
        $this->assertEnginePair($opportunity, $attempt);
        if (! is_string($attempt->preparation_binding_sha256)
            || preg_match('/^[a-f0-9]{64}$/D', $attempt->preparation_binding_sha256) !== 1
            || ! hash_equals($attempt->preparation_binding_sha256, $this->digest($opportunity, $attempt))) {
            throw $this->conflict();
        }
    }

    public function assertHumanHandoff(SolanaSwapAttempt $attempt): void
    {
        if ($attempt->preparation_origin === self::ENGINE_ORIGIN
            && (! $attempt->signing_requested_at || ! $attempt->signing_armed_at || ! $attempt->signing_claim_hash)) {
            throw new SolanaPreparationException('Explicit wallet confirmation is required before transaction reporting.', 409);
        }
    }

    private function assertEnginePair(TradeOpportunity $opportunity, SolanaSwapAttempt $attempt): void
    {
        $engineOpportunity = $opportunity->entry_mode->value === 'auto'
            && data_get($opportunity->execution_data, 'source') === SolanaOpportunityExecutionPolicy::ENGINE_AUTO_SOURCE
            && is_string(data_get($opportunity->execution_data, 'engine_evaluation_id'))
            && is_string(data_get($opportunity->execution_data, 'engine_opportunity_id'));

        if (! $engineOpportunity || $attempt->preparation_origin !== self::ENGINE_ORIGIN
            || $opportunity->id !== $attempt->trade_opportunity_id
            || $opportunity->user_id !== $attempt->user_id
            || $opportunity->chain !== Chain::Solana
            || $opportunity->execution_mode !== ExecutionMode::Live
            || $opportunity->address !== $attempt->output_mint
            || $attempt->input_mint !== SolanaSwapQuoteService::SOL_MINT
            || $attempt->network !== self::NETWORK) {
            throw $this->conflict();
        }
    }

    private function digest(TradeOpportunity $opportunity, SolanaSwapAttempt $attempt): string
    {
        try {
            $transaction = $attempt->prepared_transaction;
        } catch (DecryptException) {
            throw $this->conflict();
        }
        if (! is_string($transaction) || $transaction === '' || ! $attempt->expires_at
            || ! is_string($attempt->request_id) || ! is_string($attempt->message_hash)
            || ! is_string($attempt->recent_blockhash)) {
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
                'network' => $attempt->network,
                'input_mint' => $attempt->input_mint,
                'output_mint' => $attempt->output_mint,
                'input_amount_lamports' => (string) $attempt->input_amount_lamports,
                'slippage_bps' => (int) $attempt->slippage_bps,
                'request_id' => $attempt->request_id,
                'message_hash' => $attempt->message_hash,
                'recent_blockhash' => $attempt->recent_blockhash,
                'prepared_transaction_sha256' => hash('sha256', $transaction),
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

    private function conflict(): SolanaPreparationException
    {
        return new SolanaPreparationException('The prepared Solana attempt failed its integrity check.', 409);
    }
}
