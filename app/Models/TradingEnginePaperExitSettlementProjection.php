<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TradingEnginePaperExitSettlementProjection extends Model
{
    protected $fillable = [
        'position_projection_id',
        'position_state_id',
        'wallet_projection_id',
        'user_id',
        'engine_settlement_id',
        'engine_decision_id',
        'engine_order_id',
        'engine_fill_id',
        'engine_ledger_transaction_id',
        'engine_event_id',
        'cost_basis_native',
        'proceeds_native',
        'realized_pnl_native',
        'exit_price_usd',
        'exit_market_cap_usd',
        'observed_multiple',
        'fill_model',
        'payload_sha256',
        'settled_at',
        'projected_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Trading engine PAPER exit settlement projections are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Trading engine PAPER exit settlement projections are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'settled_at' => 'immutable_datetime',
            'projected_at' => 'immutable_datetime',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(TradingEnginePaperPositionProjection::class, 'position_projection_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(TradingEnginePaperPositionState::class, 'position_state_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(TradingEnginePaperWalletProjection::class, 'wallet_projection_id');
    }
}
