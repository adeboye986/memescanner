<?php

namespace App\Services;

class SolanaQuoteLimitService
{
    private const MAXIMUM_SUGGESTED_SPEND_LAMPORTS = 1_000_000;

    public function __construct(private ApplicationSettingsService $settings) {}

    public function maximumLamports(): int
    {
        $amount = trim((string) $this->settings->get('risk.max_trade_amount'));

        if (preg_match('/^(\d+)(?:\.(\d{1,9}))?$/', $amount, $matches) !== 1) {
            return 1;
        }

        $lamports = ltrim($matches[1].str_pad($matches[2] ?? '', 9, '0'), '0') ?: '0';
        $maximumInteger = (string) PHP_INT_MAX;

        if (strlen($lamports) > strlen($maximumInteger)
            || (strlen($lamports) === strlen($maximumInteger) && strcmp($lamports, $maximumInteger) > 0)) {
            return 1;
        }

        return max(1, (int) $lamports);
    }

    public function suggestedSpendLamports(int $walletLamports): int
    {
        if ($walletLamports <= 0) {
            return 0;
        }

        $availableWithinRiskLimit = min($walletLamports, $this->maximumLamports());

        return min(
            self::MAXIMUM_SUGGESTED_SPEND_LAMPORTS,
            intdiv($availableWithinRiskLimit, 10),
        );
    }
}
