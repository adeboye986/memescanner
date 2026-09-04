<?php

namespace App\Services;

use App\Models\UserTelegramBot;
use DomainException;

class TelegramUpdateService
{
    public function __construct(
        private TelegramBotManager $bots,
        private TelegramService $legacyTelegram,
        private TelegramLinkService $links,
        private TelegramCommandRouter $commands,
        private TelegramCallbackRouter $callbacks,
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(?UserTelegramBot $bot, array $update): void
    {
        $telegram = $bot ? $this->bots->client($bot) : $this->legacyTelegram->client();

        if (isset($update['callback_query'])) {
            $this->handleCallback($bot, $telegram, $update['callback_query']);

            return;
        }

        $message = $update['message'] ?? [];
        $chatId = (string) data_get($message, 'chat.id', '');
        $from = (array) ($message['from'] ?? []);
        $text = trim((string) ($message['text'] ?? ''));

        if (data_get($message, 'chat.type') !== 'private') {
            $telegram->sendMessage($chatId, 'Interactive trading controls are available only in a private chat with the bot.');

            return;
        }

        if (preg_match('/^\/start\s+link_([A-Za-z0-9]+)$/', $text, $matches) === 1) {
            if (! $bot) {
                $telegram->sendMessage($chatId, 'Create a new linking link from your personal Telegram Bot settings.');

                return;
            }

            try {
                $identity = $this->links->consume($matches[1], $from, $chatId, $bot);
                $telegram->sendMessage($chatId, "✅ <b>Account linked securely.</b>\n\nWelcome, ".$this->escape($identity->display_name ?: 'trader').'.', $this->commands->mainKeyboard());
            } catch (DomainException $exception) {
                $telegram->sendMessage($chatId, '❌ '.$this->escape($exception->getMessage()));
            }

            return;
        }

        $identity = $this->links->authorized((string) ($from['id'] ?? ''), $bot);

        if (! $identity) {
            $telegram->sendMessage($chatId, 'This Telegram account is not linked. Create a secure link from your Telegram Bot settings.');

            return;
        }

        $identity->update(['telegram_chat_id' => $chatId, 'last_seen_at' => now()]);
        $this->commands->handle($telegram, $identity, $chatId, $text);
    }

    /** @param array<string, mixed> $callback */
    private function handleCallback(?UserTelegramBot $bot, TelegramBotClient $telegram, array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $chatId = (string) data_get($callback, 'message.chat.id', '');
        $messageId = (int) data_get($callback, 'message.message_id', 0);
        $identity = $this->links->authorized((string) data_get($callback, 'from.id', ''), $bot);

        if (! ($callback['_acknowledged'] ?? false)) {
            $telegram->answerCallbackQuery($callbackId);
        }

        if (data_get($callback, 'message.chat.type') !== 'private') {
            $telegram->sendMessage($chatId, 'Interactive trading controls are available only in a private chat with the bot.');

            return;
        }

        if (! $identity) {
            $telegram->sendMessage($chatId, 'This Telegram account is not authorized.');

            return;
        }

        $identity->update(['telegram_chat_id' => $chatId, 'last_seen_at' => now()]);
        $this->callbacks->handle($telegram, $identity, $chatId, $messageId, (string) ($callback['data'] ?? ''));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
