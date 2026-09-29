<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class TradingEngineOpportunityLink extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'trade_opportunity_id',
        'engine_opportunity_id',
        'recorded_event_id',
        'user_id',
        'discovery_key',
        'scanner',
        'network_id',
        'asset_address',
        'recorded_at',
        'linked_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Trading engine opportunity links are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Trading engine opportunity links are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
            'linked_at' => 'immutable_datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(TradeOpportunity::class, 'trade_opportunity_id');
    }

    public function recordedEvent(): BelongsTo
    {
        return $this->belongsTo(TradingEngineEvent::class, 'recorded_event_id', 'event_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(TradingEngineOpportunityEvaluation::class, 'opportunity_link_id');
    }
}
