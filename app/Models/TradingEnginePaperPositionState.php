<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TradingEnginePaperPositionState extends Model
{
    protected $fillable = [
        'position_projection_id',
        'lifecycle_version',
        'next_observation_sequence',
        'state',
        'protection_state',
        'last_observation_id',
        'last_observation_sequence',
        'last_market_cap_usd',
        'last_price_usd',
        'last_liquidity_usd',
        'observed_multiple',
        'peak_market_cap_usd',
        'peak_multiple',
        'max_drawdown_percent',
        'last_event_id',
        'last_payload_sha256',
        'last_observed_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'lifecycle_version' => 'integer',
            'next_observation_sequence' => 'integer',
            'last_observation_sequence' => 'integer',
            'last_observed_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(TradingEnginePaperPositionProjection::class, 'position_projection_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(TradingEnginePaperFinancialObservation::class, 'position_state_id');
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(TradingEnginePaperExitSettlementProjection::class, 'position_state_id');
    }
}
