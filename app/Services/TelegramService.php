<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramService
{
    protected string $botToken;

    protected string $chatId;

    protected bool $enabled;

    public function __construct(ApplicationSettingsService $settings)
    {
        $this->botToken = (string) $settings->getSecret('telegram.bot_token');
        $this->chatId = (string) $settings->getSecret('telegram.chat_id');
        $this->enabled = (bool) $settings->get('telegram.enabled');
    }

    public function send(string $message): void
    {
        $this->sendMessage($this->chatId, $message);
    }

    /** @param array<int, array<int, array<string, string>>> $keyboard */
    public function sendMessage(string $chatId, string $message, array $keyboard = []): array
    {
        if (! $this->enabled) {
            return [];
        }

        if ($chatId === '') {
            throw new RuntimeException('Telegram chat ID is not configured.');
        }

        return $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $keyboard === [] ? null : ['inline_keyboard' => $keyboard],
        ], fn (mixed $value): bool => $value !== null));
    }

    /** @param array<int, array<int, array<string, string>>> $keyboard */
    public function editMessageText(string $chatId, int $messageId, string $message, array $keyboard = []): array
    {
        return $this->call('editMessageText', array_filter([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $keyboard === [] ? null : ['inline_keyboard' => $keyboard],
        ], fn (mixed $value): bool => $value !== null));
    }

    public function answerCallbackQuery(string $callbackId, ?string $message = null): array
    {
        return $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $callbackId, 'text' => $message]));
    }

    public function setWebhook(string $url, string $secret): array
    {
        return $this->call('setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message', 'callback_query']], false);
    }

    public function webhookInfo(): array
    {
        return $this->call('getWebhookInfo', [], false);
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => false], false);
    }

    /** @param array<string, mixed> $payload */
    private function call(string $method, array $payload, bool $requireEnabled = true): array
    {
        if ($requireEnabled && ! $this->enabled) {
            return [];
        }

        if ($this->botToken === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }
        $response = Http::withOptions([
            'force_ip_resolve' => 'v4',
        ])
            ->connectTimeout(10)
            ->timeout(30)
            ->retry(
                3,
                1000,
                function ($exception, $request) {
                    return $exception instanceof ConnectionException;
                }
            )
            ->post(
                "https://api.telegram.org/bot{$this->botToken}/{$method}",
                $payload,
            );

        if ($response->failed()) {
            throw new RuntimeException(
                "Telegram API {$method} failed with HTTP {$response->status()}."
            );
        }

        $result = $response->json('result');

        return is_array($result) ? $result : [];
    }
}
