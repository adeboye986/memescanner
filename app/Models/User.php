<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'is_admin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function telegramBot(): HasOne
    {
        return $this->hasOne(UserTelegramBot::class);
    }

    public function tradingPreference(): HasOne
    {
        return $this->hasOne(UserTradingPreference::class);
    }

    public function paperWallets(): HasMany
    {
        return $this->hasMany(PaperWallet::class);
    }

    public function paperPositions(): HasMany
    {
        return $this->hasMany(PaperPosition::class);
    }

    public function tradeOpportunities(): HasMany
    {
        return $this->hasMany(TradeOpportunity::class);
    }
}
