<?php

namespace App\Models;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Services\SolanaOpportunityExecutionPolicy;
use App\Services\SolanaPreparedAttemptIntegrity;
use App\Services\SolanaSwapQuoteService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolanaSwapAttempt extends Model
{
    protected $fillable = [
        'trade_opportunity_id', 'wallet_address', 'input_mint', 'network',
        'preparation_token', 'preparation_expires_at', 'preparation_origin',
        'preparation_binding_sha256', 'signing_requested_at', 'signing_claim_hash',
        'signing_armed_at', 'acquired_raw_amount', 'token_decimals',
        'user_id', 'connected_wallet_id', 'request_id', 'output_mint',
        'input_amount_lamports', 'slippage_bps', 'message_hash',
        'recent_blockhash', 'prepared_transaction', 'status',
        'transaction_signature',
        'provider_error_code', 'provider_error_message', 'expires_at',
        'submitted_at', 'confirmed_at', 'failed_at', 'failure_reason',
        'network_fee_lamports', 'slot',
    ];

    protected $hidden = ['prepared_transaction', 'preparation_token', 'preparation_binding_sha256', 'signing_claim_hash'];

    protected static function booted(): void
    {
        static::saving(function (SolanaSwapAttempt $attempt): void {
            $identity = ['trade_opportunity_id', 'user_id', 'connected_wallet_id', 'wallet_address', 'input_mint',
                'output_mint', 'input_amount_lamports', 'slippage_bps', 'network', 'preparation_origin'];
            if ($attempt->exists && ($attempt->getOriginal('trade_opportunity_id') !== null || $attempt->trade_opportunity_id !== null)) {
                if ($attempt->isDirty($identity)) {
                    throw new DomainException('A Solana opportunity execution binding cannot be changed.');
                }
                if ($attempt->getOriginal('preparation_binding_sha256') !== null
                    && $attempt->isDirty(['preparation_binding_sha256', 'request_id', 'message_hash', 'recent_blockhash', 'prepared_transaction', 'expires_at'])) {
                    throw new DomainException('A prepared Solana integrity binding cannot be changed.');
                }

                return;
            }
            if ($attempt->trade_opportunity_id === null) {
                return;
            }

            $opportunity = TradeOpportunity::query()->find($attempt->trade_opportunity_id);
            $wallet = ConnectedWallet::query()->find($attempt->connected_wallet_id);
            if (! $opportunity || ! $wallet || $opportunity->user_id !== $attempt->user_id
                || $wallet->user_id !== $attempt->user_id || $opportunity->chain !== Chain::Solana
                || $wallet->chain !== Chain::Solana || $opportunity->execution_mode !== ExecutionMode::Live
                || $opportunity->entry_mode !== EntryMode::Auto || $opportunity->status !== TradeOpportunityStatus::Executing
                || ! app(SolanaOpportunityExecutionPolicy::class)->permitsConfirmFirstFlow($opportunity)
                || ! $wallet->isVerified() || $attempt->wallet_address !== $wallet->address
                || $attempt->output_mint !== $opportunity->address
                || $attempt->input_mint !== SolanaSwapQuoteService::SOL_MINT
                || $attempt->network !== SolanaPreparedAttemptIntegrity::NETWORK
                || $attempt->preparation_origin !== SolanaPreparedAttemptIntegrity::ENGINE_ORIGIN
                || $attempt->status !== 'reserved' || $attempt->prepared_transaction !== null || $attempt->expires_at !== null) {
                throw new DomainException('Invalid Solana opportunity execution binding.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'prepared_transaction' => 'encrypted',
            'input_amount_lamports' => 'integer',
            'slippage_bps' => 'integer',
            'token_decimals' => 'integer',
            'preparation_expires_at' => 'datetime',
            'signing_requested_at' => 'datetime',
            'signing_armed_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connectedWallet(): BelongsTo
    {
        return $this->belongsTo(ConnectedWallet::class);
    }

    public function tradeOpportunity(): BelongsTo
    {
        return $this->belongsTo(TradeOpportunity::class);
    }
}
