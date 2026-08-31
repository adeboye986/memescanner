<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaperPositionSnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'raw_data' => 'array',
        'recorded_at' => 'datetime',
        'market_cap' => 'float',
        'price' => 'float',
        'liquidity' => 'float',
        'return_percent' => 'float',
        'multiple' => 'float',
        'drawdown_from_peak_percent' => 'float',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(PaperPosition::class, 'paper_position_id');
    }
}
