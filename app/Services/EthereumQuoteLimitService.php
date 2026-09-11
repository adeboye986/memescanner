<?php

namespace App\Services;

class EthereumQuoteLimitService
{
    public function __construct(private ApplicationSettingsService $settings) {}

    public function maximumWei(): string
    {
        $amount = trim((string) $this->settings->get('risk.max_trade_amount'));
        if (preg_match('/^(\d+)(?:\.(\d{1,18}))?$/', $amount, $matches) !== 1) return '1';

        return ltrim($matches[1].str_pad($matches[2] ?? '', 18, '0'), '0') ?: '1';
    }

    public function exceeds(string $left, string $right): bool
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';

        return strlen($left) > strlen($right) || (strlen($left) === strlen($right) && strcmp($left, $right) > 0);
    }
}
