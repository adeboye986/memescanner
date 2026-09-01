<?php

namespace App\Services;

class NewTokenClassificationService
{
    /** @return array{classification: string, label: string, trade_eligible: bool, no_trade_message: string} */
    public function classify(int $score, bool $securityUnavailable, int $strongThreshold = 50): array
    {
        if ($securityUnavailable) {
            return [
                'classification' => 'unverified',
                'label' => $score >= $strongThreshold ? '⚠️ UNVERIFIED CANDIDATE' : '⚠️ UNVERIFIED WATCHLIST',
                'trade_eligible' => false,
                'no_trade_message' => 'Security unverified — no paper trade opened.',
            ];
        }

        if ($score >= $strongThreshold) {
            return [
                'classification' => 'strong',
                'label' => '🟢 STRONG CANDIDATE',
                'trade_eligible' => true,
                'no_trade_message' => '',
            ];
        }

        return [
            'classification' => 'watchlist',
            'label' => '🟡 WATCHLIST',
            'trade_eligible' => false,
            'no_trade_message' => 'Scanner watchlist only — no paper trade opened.',
        ];
    }
}
