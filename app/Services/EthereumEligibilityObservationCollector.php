<?php

namespace App\Services;

use DomainException;

/**
 * Trusted boundary for server-held, immutable observations. Never return request-supplied facts.
 * Phase 4C-2 must implement collection/storage and bind the opaque reference to token identity.
 */
class EthereumEligibilityObservationCollector
{
    /** @return array{chain_id: int, token_address: string, code_sha256: string, block_number: string, block_hash: string, collected_at: string} */
    public function collect(string $token, string $reference): array
    {
        throw new DomainException('Trusted Ethereum review observations are unavailable.');
    }
}
