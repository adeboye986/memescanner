<?php

namespace App\Services;

use App\Models\TradeOpportunity;
use DomainException;

class EthereumOpportunityFreshness
{
    public function isFresh(TradeOpportunity $opportunity): bool
    {
        try {
            $this->assertFresh($opportunity);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    public function assertFresh(TradeOpportunity $opportunity): void
    {
        $maximumAge = $this->maximumAgeSeconds();
        if (! $opportunity->qualified_at || $opportunity->qualified_at->isFuture()
            || $opportunity->qualified_at->lt(now()->subSeconds($maximumAge)->startOfSecond())) {
            throw new DomainException('This opportunity is stale. A newly qualified opportunity is required.');
        }
    }

    /** Qualification age only: allow 1–900 seconds (15 minutes), default 300. */
    private function maximumAgeSeconds(): int
    {
        $configured = config('services.ethereum.opportunity_max_age_seconds', 300);
        $seconds = filter_var($configured, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 900]]);
        if ((! is_int($configured) && ! is_string($configured))
            || preg_match('/^[1-9][0-9]*$/D', (string) $configured) !== 1 || $seconds === false) {
            throw new DomainException('Live opportunity freshness configuration is invalid. Contact an administrator.');
        }

        return $seconds;
    }
}
