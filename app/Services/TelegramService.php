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
        if (! $this->enabled) {
            return;
        }

        if ($this->botToken === '' || $this->chatId === '') {
            throw new RuntimeException('Telegram is not configured.');
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
                "https://api.telegram.org/bot{$this->botToken}/sendMessage",
                [
                    'chat_id' => $this->chatId,
                    'text' => $message,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]
            );

        if ($response->failed()) {
            throw new RuntimeException(
                'Telegram API error: '.
                $response->status().
                ' - '.
                $response->body()
            );
        }
    }
}
