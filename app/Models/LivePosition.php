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
        return ['chain' => Chain::class, 'entry_confirmed_at' => 'datetime', 'token_decimals' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (LivePosition $position): void {
            if ($position->isDirty(['user_id', 'chain', 'network', 'wallet_address', 'connected_wallet_id',
                'trade_opportunity_id', 'ethereum_swap_attempt_id', 'token_address',
                'entry_transaction_hash', 'entry_block_number', 'entry_confirmed_at'])) {
                throw new DomainException('LIVE entry identity and confirmation evidence are immutable.');
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
