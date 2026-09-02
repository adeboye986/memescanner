<?php

namespace App\Models;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use Database\Factories\TradeOpportunityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradeOpportunity extends Model
{
    /** @use HasFactory<TradeOpportunityFactory> */
    use HasFactory;

    protected $fillable = [
        'chain',
        'address',
        'symbol',
        'name',
        'scanner',
        'status',
        'execution_mode',
        'entry_mode',
        'pair_address',
        'price',
        'market_cap',
        'liquidity',
        'volume',
        'qualification_data',
        'security_data',
        'execution_data',
        'paper_position_id',
        'qualified_at',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'chain' => Chain::class,
            'status' => TradeOpportunityStatus::class,
            'execution_mode' => ExecutionMode::class,
            'entry_mode' => EntryMode::class,
            'qualification_data' => 'array',
            'security_data' => 'array',
            'execution_data' => 'array',
            'qualified_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function paperPosition(): BelongsTo
    {
        return $this->belongsTo(PaperPosition::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TradeOpportunityEvent::class);
    }
}
