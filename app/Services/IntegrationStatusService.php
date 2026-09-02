<?php

namespace App\Services;

class IntegrationStatusService
{
    public function __construct(
        private ApplicationSettingsService $settings,
        private PaperTrackerHealthService $trackerHealth,
    ) {}

    /** @return array<string, array{label: string, status: string, detail: string}> */
    public function all(): array
    {
        $tracker = $this->trackerHealth->status();
        $telegramConfigured = (bool) $this->settings->get('telegram.enabled')
            && $this->settings->getSecret('telegram.bot_token') !== null
            && $this->settings->getSecret('telegram.chat_id') !== null;

        return [
            'telegram' => [
                'label' => 'Telegram',
                'status' => $telegramConfigured ? 'configured' : 'not_configured',
                'detail' => $telegramConfigured ? 'Credentials stored; use Test Telegram to verify.' : 'Token and destination are required.',
            ],
            'solana' => [
                'label' => 'Solana',
                'status' => $this->settings->getSecret('blockchain.solana_rpc_url') ? 'configured' : 'not_configured',
                'detail' => 'Configured does not imply a successful live health check.',
            ],
            'ethereum' => [
                'label' => 'Ethereum',
                'status' => 'configured',
                'detail' => 'GeckoTerminal discovery provider is configured in the application.',
            ],
            'market_data' => [
                'label' => 'Market Data',
                'status' => 'configured',
                'detail' => $this->settings->getSecret('market_data.birdeye_api_key')
                    ? 'DexScreener and Birdeye are configured.'
                    : 'DexScreener is available; Birdeye credentials are not configured.',
            ],
            'tracker' => [
                'label' => 'Fast Tracker',
                'status' => $tracker['status'],
                'detail' => $tracker['last_tracker_check']?->diffForHumans() ?? 'No tracker heartbeat recorded.',
            ],
        ];
    }
}
