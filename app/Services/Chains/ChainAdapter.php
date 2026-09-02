<?php

namespace App\Services\Chains;

use App\Chain;

interface ChainAdapter
{
    public function chain(): Chain;

    /** @return array<string, mixed> */
    public function marketData(string $address): array;

    /**
     * @param  list<string>  $addresses
     * @return array<string, array<string, mixed>>
     */
    public function marketDataMany(array $addresses): array;

    /** @return list<array<string, mixed>> */
    public function latestProfiles(int $limit = 20): array;

    /** @return list<string> */
    public function unavailableSecurityChecks(): array;
}
