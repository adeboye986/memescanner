<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaperPosition extends Model
{
    protected $guarded = [];

    protected $casts = [
        'milestones' => 'array',
        'meta' => 'array',
        'entry_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'closed_at' => 'datetime',

        'discovery_market_cap' => 'float',
        'entry_market_cap' => 'float',
        'entry_price' => 'float',
        'entry_liquidity' => 'float',
        'move_since_discovery_percent' => 'float',

        'last_market_cap' => 'float',
        'last_price' => 'float',
        'peak_market_cap' => 'float',
        'peak_multiple' => 'float',
        'max_drawdown_percent' => 'float',

        'remaining_fraction' => 'float',
        'realized_value_multiple' => 'float',
        'strategy_value_multiple' => 'float',
        'strategy_return_percent' => 'float',

        'tp_50_hit' => 'boolean',
        'tp_2x_hit' => 'boolean',
        'stop_loss_hit' => 'boolean',
        'trailing_stop_hit' => 'boolean',

        'exit_events' => 'array',
    ];

    public function snapshots(): HasMany
    {
        return $this->hasMany(PaperPositionSnapshot::class);
    }
}
