<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserTelegramBot;
use DomainException;
use Illuminate\Support\Str;

class TelegramBotManager
{
    public function client(UserTelegramBot $bot): TelegramBotClient
    {
        return new TelegramBotClient((string) $bot->bot_token);
    }

    public function connect(User $user, ?string $newToken, string $submittedUsername): UserTelegramBot
    {
        $existing = UserTelegramBot::query()->where('user_id', $user->id)->first();
        $token = $newToken ?: (string) $existing?->bot_token;

        if ($token === '') {
            throw new DomainException('A bot token is required when connecting a bot.');
        }

        $username = ltrim(trim($submittedUsername), '@');
        $client = new TelegramBotClient($token);
        $profile = $client->getMe();
        $verifiedUsername = ltrim((string) ($profile['username'] ?? ''), '@');

        if (! ($profile['is_bot'] ?? false) || ! isset($profile['id']) || $verifiedUsername === '') {
            throw new DomainException('Telegram did not return a valid bot identity.');
        }

        if (strcasecmp($username, $verifiedUsername) !== 0) {
            throw new DomainException('The supplied bot username does not match the token.');
        }

        $claimedByAnotherUser = UserTelegramBot::query()->where('telegram_bot_id', (string) $profile['id'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists();

        if ($claimedByAnotherUser) {
            throw new DomainException('This Telegram bot is already connected to another platform account.');
        }

        $publicId = $existing?->public_id ?? Str::random(32);
        $webhookSecret = $existing?->webhook_secret ?? Str::random(48);
        $webhookUrl = rtrim((string) config('app.url'), '/').route('telegram.user-webhook', ['publicId' => $publicId], absolute: false);

        if (! Str::startsWith($webhookUrl, 'https://')) {
            throw new DomainException('A public HTTPS APP_URL is required before connecting a Telegram bot.');
        }

        $client->setWebhook($webhookUrl, (string) $webhookSecret);

        $values = [
            'public_id' => $publicId,
            'bot_username' => $verifiedUsername,
            'webhook_secret' => $webhookSecret,
            'telegram_bot_id' => (string) $profile['id'],
            'display_name' => trim((string) ($profile['first_name'] ?? '')) ?: $verifiedUsername,
            'enabled' => true,
            'webhook_configured_at' => now(),
            'last_verified_at' => now(),
        ];

        if ($newToken) {
            $values['bot_token'] = $newToken;
        }

        $bot = UserTelegramBot::query()->updateOrCreate(
            ['user_id' => $user->id],
            $values,
        );

        return $bot->fresh();
    }

    public function disconnect(UserTelegramBot $bot): void
    {
        $this->client($bot)->deleteWebhook();
        $bot->update(['enabled' => false, 'webhook_configured_at' => null]);
    }

    public function verify(UserTelegramBot $bot): void
    {
        $this->client($bot)->getMe();
        $bot->update(['last_verified_at' => now()]);
    }
}
