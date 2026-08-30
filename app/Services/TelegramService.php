<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramService
{
    protected string $botToken;
    protected string $chatId;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token');
        $this->chatId = config('services.telegram.chat_id');
    }

    public function send(string $message): void
    {
        $response = Http::withOptions([
                'force_ip_resolve' => 'v4',
            ])
            ->connectTimeout(10)
            ->timeout(30)
            ->retry(
                3,
                1000,
                function ($exception, $request) {
                    return $exception instanceof
                        \Illuminate\Http\Client\ConnectionException;
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
                'Telegram API error: ' .
                $response->status() .
                ' - ' .
                $response->body()
            );
        }
    }
}