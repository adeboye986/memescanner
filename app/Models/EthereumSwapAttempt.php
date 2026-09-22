<?php

namespace App\Models;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EthereumSwapAttempt extends Model
{
    protected $fillable = [
        'trade_opportunity_id', 'wallet_address',
        'user_id', 'connected_wallet_id', 'buy_token', 'sell_amount_wei',
        'slippage_bps', 'quote_id', 'transaction_payload', 'status',
        'transaction_hash', 'expires_at', 'submitted_at', 'confirmed_at',
        'failed_at', 'failure_reason', 'block_number', 'gas_used', 'effective_gas_price_wei',
        'actual_network_fee_wei',
    ];

    protected $hidden = ['transaction_payload'];

    protected static function booted(): void
    {
        static::saving(function (EthereumSwapAttempt $attempt): void {
            $binding = ['trade_opportunity_id', 'user_id', 'connected_wallet_id', 'wallet_address', 'buy_token', 'sell_amount_wei', 'slippage_bps'];
            if ($attempt->exists && ($attempt->getOriginal('trade_opportunity_id') !== null || $attempt->trade_opportunity_id !== null)) {
                if ($attempt->isDirty($binding)) {
                    throw new DomainException('An opportunity execution binding cannot be changed.');
                }

                return;
            }
            if ($attempt->trade_opportunity_id === null) {
                return;
            }

            $opportunity = TradeOpportunity::query()->find($attempt->trade_opportunity_id);
            $wallet = ConnectedWallet::query()->find($attempt->connected_wallet_id);
            if (! $opportunity || ! $wallet
                || $opportunity->user_id !== $attempt->user_id || $wallet->user_id !== $attempt->user_id
                || $opportunity->chain !== Chain::Ethereum || $wallet->chain !== Chain::Ethereum
                || $opportunity->entry_mode !== EntryMode::Confirm || $opportunity->execution_mode !== ExecutionMode::Live
                || $opportunity->status !== TradeOpportunityStatus::Executing
                || ! $wallet->isVerified()
                || $attempt->wallet_address !== strtolower($wallet->address)
                || $attempt->buy_token !== strtolower($opportunity->address)
                || $attempt->status !== 'reserved' || $attempt->transaction_payload !== null || $attempt->expires_at !== null) {
                throw new DomainException('Invalid Ethereum opportunity execution binding.');
            }
        });
    }

    public function tradeOpportunity(): BelongsTo
    {
        return $this->belongsTo(TradeOpportunity::class);
    }

    protected function casts(): array
    {
        return ['transaction_payload' => 'encrypted:array', 'expires_at' => 'datetime', 'submitted_at' => 'datetime', 'confirmed_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    public function connectedWallet(): BelongsTo
    {
        return $this->belongsTo(ConnectedWallet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
