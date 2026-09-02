<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class IntegrationConnectionService
{
    public function __construct(
        private ApplicationSettingsService $settings,
        private TelegramService $telegram,
    ) {}

    public function test(string $integration): string
    {
        return match ($integration) {
            'telegram' => $this->telegram(),
            'solana' => $this->solana(),
            'ethereum' => $this->ethereum(),
            'market-data' => $this->marketData(),
            default => throw new \InvalidArgumentException('Unknown integration.'),
        };
    }

    private function telegram(): string
    {
        if (! $this->settings->get('telegram.enabled')) {
            throw new RuntimeException('Telegram is disabled.');
        }

        $this->telegram->send('Meme Scanner Telegram integration is working.');

        return 'Telegram test message sent successfully.';
    }

    private function solana(): string
    {
        $url = $this->settings->getSecret('blockchain.solana_rpc_url');

        if (! $url) {
            throw new RuntimeException('Solana RPC is not configured.');
        }

        $response = Http::connectTimeout(3)->timeout(8)->post($url, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'getHealth',
        ]);

        if (! $response->successful() || $response->json('result') !== 'ok') {
            throw new RuntimeException('Solana RPC health check failed.');
        }

        return 'Solana RPC connection is healthy.';
    }

    private function ethereum(): string
    {
        $response = Http::connectTimeout(3)->timeout(8)->acceptJson()
            ->get('https://api.geckoterminal.com/api/v2/networks/eth/new_pools', ['page' => 1]);

        if (! $response->successful() || ! is_array($response->json('data'))) {
            throw new RuntimeException('Ethereum discovery provider check failed.');
        }

        return 'Ethereum market-discovery connection is healthy.';
    }

    private function marketData(): string
    {
        $response = Http::connectTimeout(3)->timeout(8)->acceptJson()
            ->get('https://api.dexscreener.com/latest/dex/search', ['q' => 'SOL/USDC']);

        if (! $response->successful() || ! is_array($response->json('pairs'))) {
            throw new RuntimeException('Market-data provider check failed.');
        }

        return 'DexScreener market-data connection is healthy.';
    }
}
