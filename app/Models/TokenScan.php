<?php

namespace App\Models;

use App\Chain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TokenScan extends Model
{
    protected $fillable = [
        'chain',
        'address',
        'symbol',
        'name',
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
        'raw_data',
        'first_seen_at',
        'last_scanned_at',
        'security_score',
        'security_passed',
        'security_risks',
        'follow_up_status',
        'last_follow_up_alerted_at',
    ];

    protected $casts = [
        'chain' => Chain::class,
        'raw_data' => 'array',
        'first_seen_at' => 'datetime',
        'last_scanned_at' => 'datetime',
        'security_passed' => 'boolean',
        'security_risks' => 'array',
        'last_follow_up_alerted_at' => 'datetime',
    ];

    public function histories(): HasMany
    {
        return $this->hasMany(TokenScanHistory::class);
    }
}
