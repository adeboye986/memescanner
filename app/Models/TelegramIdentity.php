<?php

namespace App\Models;

use Database\Factories\TelegramIdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramIdentity extends Model
{
    /** @use HasFactory<TelegramIdentityFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'telegram_user_id', 'telegram_chat_id', 'telegram_username', 'display_name', 'status', 'linked_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['linked_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
