<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotClient
{
    public function __construct(private readonly string $botToken) {}

    /** @return array<string, mixed> */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    /** @param array<int, array<int, array<string, string>>> $keyboard */
    public function sendMessage(string $chatId, string $message, array $keyboard = []): array
    {
        return $this->call('sendMessage', $this->messagePayload($chatId, $message, $keyboard));
    }

    /** @param array<int, array<int, array<string, string>>> $keyboard */
    public function editMessageText(string $chatId, int $messageId, string $message, array $keyboard = []): array
    {
        return $this->call('editMessageText', [...$this->messagePayload($chatId, $message, $keyboard), 'message_id' => $messageId]);
    }

    public function answerCallbackQuery(string $callbackId, ?string $message = null): array
    {
        return $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $callbackId, 'text' => $message]));
    }

    public function setWebhook(string $url, string $secret): array
    {
        return $this->call('setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message', 'callback_query']]);
    }

    public function webhookInfo(): array
    {
        return $this->call('getWebhookInfo');
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => false]);
    }

    /** @param array<string, mixed> $payload */
    private function call(string $method, array $payload = []): array
    {
        if ($this->botToken === '') {
            throw new RuntimeException('Telegram bot credentials are not configured.');
        }

        $response = Http::withOptions(['force_ip_resolve' => 'v4'])->connectTimeout(10)->timeout(30)
            ->retry(3, 1000, fn ($exception): bool => $exception instanceof ConnectionException)
            ->post("https://api.telegram.org/bot{$this->botToken}/{$method}", $payload);

        if ($response->failed() || ! $response->json('ok')) {
            throw new RuntimeException("Telegram API {$method} failed. Verify the bot credentials and try again.");
        }

        $result = $response->json('result');

        return is_array($result) ? $result : [];
    }

    /** @param array<int, array<int, array<string, string>>> $keyboard
     * @return array<string, mixed>
     */
    private function messagePayload(string $chatId, string $message, array $keyboard): array
    {
        return array_filter([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $keyboard === [] ? null : ['inline_keyboard' => $keyboard],
        ], fn (mixed $value): bool => $value !== null);
    }
}
