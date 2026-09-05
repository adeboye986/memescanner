<?php

namespace App\Services;

use App\Models\TelegramIdentity;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Models\UserTelegramBot;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramLinkService
{
    public function __construct(private ApplicationSettingsService $settings) {}

    public function create(User $user): string
    {
        $botUsername = ltrim(trim((string) $this->settings->get('telegram.bot_username')), '@');
        $enabled = (bool) $this->settings->get('telegram.enabled');
        $botToken = $this->settings->getSecret('telegram.bot_token');
        $webhookSecret = $this->settings->getSecret('telegram.webhook_secret');

        if (! $enabled || $botUsername === '' || ! $botToken || ! $webhookSecret) {
            throw new DomainException('The platform Telegram bot is not available right now. Please try again later.');
        }

        $token = Str::random(40);

        DB::transaction(function () use ($user, $token): void {
            TelegramLinkToken::query()->where('user_id', $user->id)->whereNull('consumed_at')->delete();
            TelegramLinkToken::query()->create([
                'user_id' => $user->id,
                'user_telegram_bot_id' => null,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(10),
            ]);
        });

        return "https://t.me/{$botUsername}?start=link_{$token}";
    }

    /** @param array<string, mixed> $from */
    public function consume(string $token, array $from, string $chatId, ?UserTelegramBot $bot = null): TelegramIdentity
    {
        $telegramUserId = isset($from['id']) ? (string) $from['id'] : '';

        if (preg_match('/^[1-9]\d*$/', $telegramUserId) !== 1) {
            throw new DomainException('Telegram identity is invalid.');
        }

        return DB::transaction(function () use ($token, $from, $chatId, $telegramUserId, $bot): TelegramIdentity {
            $query = TelegramLinkToken::query()->where('token_hash', hash('sha256', $token));

            if ($bot) {
                $query->where('user_telegram_bot_id', $bot->id);
            } else {
                $query->whereNull('user_telegram_bot_id');
            }

            $link = $query->lockForUpdate()->first();

            if (! $link || $link->consumed_at || $link->expires_at->isPast()) {
                throw new DomainException('This Telegram linking link is invalid or expired.');
            }

            $claimed = TelegramIdentity::query()
                ->where('telegram_user_id', $telegramUserId)
                ->where('user_id', '!=', $link->user_id)
                ->exists();

            if ($claimed) {
                throw new DomainException('This Telegram account is already linked elsewhere.');
            }

            $identity = TelegramIdentity::query()->updateOrCreate(
                ['user_id' => $link->user_id],
                [
                    'telegram_user_id' => $telegramUserId,
                    'user_telegram_bot_id' => $bot?->id,
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

    public function authorized(string $telegramUserId, ?UserTelegramBot $bot): ?TelegramIdentity
    {
        $query = TelegramIdentity::query()->with('user')->where('telegram_user_id', $telegramUserId)->where('status', 'active');

        if ($bot) {
            $query->where('user_telegram_bot_id', $bot->id)->where('user_id', $bot->user_id);
        } else {
            $query->whereNull('user_telegram_bot_id');
        }

        return $query->first();
    }

    public function unlink(User $user): void
    {
        DB::transaction(function () use ($user): void {
            TelegramIdentity::query()->where('user_id', $user->id)->delete();
            TelegramLinkToken::query()->where('user_id', $user->id)->delete();
        });
    }
}
