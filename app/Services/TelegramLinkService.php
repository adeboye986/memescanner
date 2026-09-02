<?php

namespace App\Services;

use App\Models\TelegramIdentity;
use App\Models\TelegramLinkToken;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramLinkService
{
    public function __construct(private ApplicationSettingsService $settings) {}

    public function create(User $user): string
    {
        $username = ltrim((string) $this->settings->get('telegram.bot_username'), '@');

        if ($username === '') {
            throw new DomainException('Configure the Telegram bot username before linking an account.');
        }

        $token = Str::random(40);
        DB::transaction(function () use ($user, $token): void {
            TelegramLinkToken::query()->where('user_id', $user->id)->whereNull('consumed_at')->delete();
            TelegramLinkToken::query()->create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(10),
            ]);
        });

        return "https://t.me/{$username}?start=link_{$token}";
    }

    /** @param array<string, mixed> $from */
    public function consume(string $token, array $from, string $chatId): TelegramIdentity
    {
        $telegramUserId = isset($from['id']) ? (string) $from['id'] : '';

        if (preg_match('/^[1-9]\d*$/', $telegramUserId) !== 1) {
            throw new DomainException('Telegram identity is invalid.');
        }

        return DB::transaction(function () use ($token, $from, $chatId, $telegramUserId): TelegramIdentity {
            $link = TelegramLinkToken::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();

            if (! $link || $link->consumed_at || $link->expires_at->isPast()) {
                throw new DomainException('This Telegram linking link is invalid or expired.');
            }

            $claimed = TelegramIdentity::query()->where('telegram_user_id', $telegramUserId)->where('user_id', '!=', $link->user_id)->exists();

            if ($claimed) {
                throw new DomainException('This Telegram account is already linked elsewhere.');
            }

            $identity = TelegramIdentity::query()->updateOrCreate(
                ['user_id' => $link->user_id],
                [
                    'telegram_user_id' => $telegramUserId,
                    'telegram_chat_id' => $chatId,
                    'telegram_username' => $from['username'] ?? null,
                    'display_name' => trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: null,
                    'status' => 'active',
                    'linked_at' => now(),
                    'last_seen_at' => now(),
                ],
            );
            $link->update(['consumed_at' => now()]);

            return $identity;
        });
    }

    public function authorized(string $telegramUserId): ?TelegramIdentity
    {
        return TelegramIdentity::query()->with('user')->where('telegram_user_id', $telegramUserId)->where('status', 'active')->first();
    }

    public function unlink(User $user): void
    {
        DB::transaction(function () use ($user): void {
            TelegramIdentity::query()->where('user_id', $user->id)->delete();
            TelegramLinkToken::query()->where('user_id', $user->id)->delete();
        });
    }
}
