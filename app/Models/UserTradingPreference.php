<?php

namespace App\Models;

use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use Database\Factories\UserTradingPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTradingPreference extends Model
{
    /** @use HasFactory<UserTradingPreferenceFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'execution_mode', 'entry_mode', 'trading_enabled'];

    protected function casts(): array
    {
        return ['execution_mode' => ExecutionMode::class, 'entry_mode' => EntryMode::class, 'trading_enabled' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
