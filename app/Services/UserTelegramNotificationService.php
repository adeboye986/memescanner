<?php

namespace App\Services;

use App\Models\TelegramIdentity;
use App\Models\User;
use RuntimeException;

class UserTelegramNotificationService
{
    public function __construct(
        private TelegramService $sharedTelegram,
        private TelegramBotManager $telegramBots,
    ) {}

    public function send(User $user, string $message): void
    {
        $identity = TelegramIdentity::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->orderByRaw('user_telegram_bot_id IS NULL DESC')
            ->with('bot')
            ->first();

        if (! $identity) {
            throw new RuntimeException('User does not have an active Telegram identity.');
        }

        if (! $identity->telegram_chat_id) {
            throw new RuntimeException('User Telegram chat ID is missing.');
        }

        if ($identity->user_telegram_bot_id === null) {
            $this->sharedTelegram->sendMessage((string) $identity->telegram_chat_id, $message);

            return;
        }

        $bot = $identity->bot;

        if (! $bot || ! $bot->enabled) {
            throw new RuntimeException('User Telegram bot is unavailable.');
        }

        $this->telegramBots
            ->client($bot)
            ->sendMessage((string) $identity->telegram_chat_id, $message);
    }
}
