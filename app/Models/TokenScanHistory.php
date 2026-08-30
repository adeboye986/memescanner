<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenScanHistory extends Model
{
    protected $fillable = [
        'token_scan_id',
        'address',
        'symbol',
        'name',
        'snapshot_type',

        'price',
        'market_cap',
        'liquidity',
        'holders',

        'volume_1m',
        'buys_1m',
        'sells_1m',
        'unique_wallets_5m',
        'price_change_5m',

        'score',

        'dex_available',
        'dex',
        'dex_pair_address',
        'dex_market_cap',
        'dex_liquidity',
        'dex_pair_age_minutes',

        'raw_data',
        'scanned_at',
    ];

    protected $casts = [
        'dex_available' => 'boolean',
        'raw_data' => 'array',
        'scanned_at' => 'datetime',
    ];

    public function tokenScan(): BelongsTo
    {
        return $this->belongsTo(TokenScan::class);
    }
}