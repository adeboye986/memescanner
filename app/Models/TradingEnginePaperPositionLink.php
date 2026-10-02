<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingEnginePaperPositionLink extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ownership_snapshot' => 'array',
            'registration_payload' => 'array',
            'registered_at' => 'immutable_datetime',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(PaperPosition::class, 'paper_position_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(TradeOpportunity::class, 'trade_opportunity_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(TradingEnginePaperObservation::class, 'position_link_id');
    }
}
