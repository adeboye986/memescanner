<?php

namespace App\Models;

use App\Chain;
use Database\Factories\LivePositionFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivePosition extends Model
{
    /** @use HasFactory<LivePositionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'chain', 'network', 'wallet_address', 'connected_wallet_id',
        'trade_opportunity_id', 'ethereum_swap_attempt_id', 'token_address',
        'entry_transaction_hash', 'entry_block_number', 'entry_confirmed_at', 'accounting_status',
    ];

    protected function casts(): array
    {
        return ['chain' => Chain::class, 'entry_confirmed_at' => 'datetime', 'token_decimals' => 'integer',
            'accounting_verified_at' => 'datetime', 'accounting_last_attempt_at' => 'datetime',
            'accounting_next_attempt_at' => 'datetime', 'accounting_lease_expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (LivePosition $position): void {
            if ($position->getRawOriginal('accounting_verified_at') !== null && $position->isDirty('accounting_status')
                && ($position->accounting_status !== 'discrepancy' || $position->getRawOriginal('accounting_status') === 'discrepancy')) {
                throw new DomainException('Verified inventory can only be suspended through discrepancy.');
            }
            if ($position->getRawOriginal('accounting_verified_at') !== null
                && $position->isDirty(['acquired_raw_amount', 'token_decimals', 'accounting_policy_version',
                    'accounting_verified_at', 'accepted_ethereum_evidence_id'])) {
                throw new DomainException('Verified inventory evidence is immutable; record a discrepancy revision.');
            }
            if ($position->isDirty(['user_id', 'chain', 'network', 'wallet_address', 'connected_wallet_id',
                'trade_opportunity_id', 'ethereum_swap_attempt_id', 'token_address',
                'entry_transaction_hash', 'entry_block_number', 'entry_confirmed_at'])) {
                throw new DomainException('LIVE entry identity and confirmation evidence are immutable.');
            }
            if ($position->isDirty('accounting_version')
                && (int) $position->accounting_version <= (int) $position->getRawOriginal('accounting_version')) {
                throw new DomainException('Accounting decision versions must advance.');
            }
            // Decision changes invalidate in-flight observations; timestamp/lease-only saves do not.
            if ($position->isDirty(['accounting_status', 'accounting_reason_code', 'accepted_ethereum_evidence_id',
                'acquired_raw_amount', 'token_decimals', 'accounting_policy_version', 'accounting_verified_at'])
                && ! $position->isDirty('accounting_version')) {
                $position->accounting_version = (int) $position->getRawOriginal('accounting_version') + 1;
            }
        });
        static::deleting(function (LivePosition $position): void {
            throw new DomainException('LIVE execution history cannot be deleted.');
        });
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

    public function ethereumSwapAttempt(): BelongsTo
    {
        return $this->belongsTo(EthereumSwapAttempt::class);
    }
}
