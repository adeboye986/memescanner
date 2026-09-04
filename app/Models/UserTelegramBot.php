<?php

namespace App\Models;

use Database\Factories\UserTelegramBotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UserTelegramBot extends Model
{
    /** @use HasFactory<UserTelegramBotFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'public_id', 'bot_token', 'bot_username', 'webhook_secret', 'telegram_bot_id', 'display_name', 'enabled', 'webhook_configured_at', 'last_verified_at'];

    protected $hidden = ['bot_token', 'webhook_secret'];

    protected function casts(): array
    {
        return ['bot_token' => 'encrypted', 'webhook_secret' => 'encrypted', 'enabled' => 'boolean', 'webhook_configured_at' => 'datetime', 'last_verified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function identity(): HasOne
    {
        return $this->hasOne(TelegramIdentity::class);
    }
}
