<?php

namespace App\Models;

use Database\Factories\TelegramLinkTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramLinkToken extends Model
{
    /** @use HasFactory<TelegramLinkTokenFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'user_telegram_bot_id', 'token_hash', 'expires_at', 'consumed_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(UserTelegramBot::class, 'user_telegram_bot_id');
    }
}
