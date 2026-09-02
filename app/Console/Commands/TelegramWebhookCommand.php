<?php

namespace App\Console\Commands;

use App\Services\ApplicationSettingsService;
use App\Services\TelegramService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

#[Signature('telegram:webhook {action : set, info, or delete} {--url= : Public HTTPS application URL} ')]
#[Description('Manage the Telegram bot webhook without exposing credentials')]
class TelegramWebhookCommand extends Command
{
    public function handle(TelegramService $telegram, ApplicationSettingsService $settings): int
    {
        try {
            return match ((string) $this->argument('action')) {
                'set' => $this->set($telegram, $settings),
                'info' => $this->showWebhookInfo($telegram),
                'delete' => $this->removeWebhook($telegram),
                default => $this->invalid(),
            };
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function set(TelegramService $telegram, ApplicationSettingsService $settings): int
    {
        $secret = $settings->getSecret('telegram.webhook_secret');
        $baseUrl = rtrim((string) ($this->option('url') ?: config('app.url')), '/');

        if (! $secret) {
            throw new RuntimeException('Configure a Telegram webhook secret first.');
        }

        if (! Str::startsWith($baseUrl, 'https://')) {
            throw new RuntimeException('Telegram requires a public HTTPS URL.');
        }

        $telegram->setWebhook($baseUrl.route('telegram.webhook', absolute: false), $secret);
        $this->info('Telegram webhook configured successfully.');

        return self::SUCCESS;
    }

    private function showWebhookInfo(TelegramService $telegram): int
    {
        $result = $telegram->webhookInfo();
        $this->line('URL: '.((string) ($result['url'] ?? '') ?: 'Not configured'));
        $this->line('Pending updates: '.(int) ($result['pending_update_count'] ?? 0));

        return self::SUCCESS;
    }

    private function removeWebhook(TelegramService $telegram): int
    {
        $telegram->deleteWebhook();
        $this->info('Telegram webhook removed.');

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('Action must be set, info, or delete.');

        return self::INVALID;
    }
}
