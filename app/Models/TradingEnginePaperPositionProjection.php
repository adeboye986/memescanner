<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TradingEnginePaperPositionProjection extends Model
{
    protected $fillable = [
        'engine_position_id',
        'wallet_projection_id',
        'paper_entry_intent_id',
        'trade_opportunity_id',
        'opportunity_evaluation_id',
        'user_id',
        'engine_intent_id',
        'engine_order_id',
        'engine_fill_id',
        'engine_opportunity_id',
        'network_id',
        'asset_address',
        'symbol',
        'state',
        'quantity',
        'quantity_unit',
        'cost_basis_native',
        'entry_price_usd',
        'entry_market_cap_usd',
        'entry_liquidity_usd',
        'strategy_snapshot',
        'authority_snapshot',
        'entry_event_id',
        'entry_payload_sha256',
        'opened_at',
        'projected_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Trading engine PAPER entry projections are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Trading engine PAPER entry projections are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'strategy_snapshot' => 'array',
            'authority_snapshot' => 'array',
            'opened_at' => 'immutable_datetime',
            'projected_at' => 'immutable_datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(TradingEnginePaperWalletProjection::class, 'wallet_projection_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(TradeOpportunity::class, 'trade_opportunity_id');
    }
}
