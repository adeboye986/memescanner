<?php

namespace App\Services;

use App\Models\TradeOpportunity;
use DomainException;

class SolanaOpportunityFreshness
{
    public function assertFresh(TradeOpportunity $opportunity): void
    {
        $configured = config('services.solana.opportunity_max_age_seconds', 300);
        $seconds = filter_var($configured, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 900]]);
        if ((! is_int($configured) && ! is_string($configured))
            || preg_match('/^[1-9][0-9]*$/D', (string) $configured) !== 1 || $seconds === false) {
            throw new DomainException('Live Solana opportunity freshness configuration is invalid.');
        }
        if (! $opportunity->qualified_at || $opportunity->qualified_at->isFuture()
            || $opportunity->qualified_at->lt(now()->subSeconds($seconds)->startOfSecond())) {
            throw new DomainException('This Solana opportunity is stale. A newly qualified opportunity is required.');
        }
    }
}
