<?php

namespace App\Services\Chains;

use App\Chain;
use App\Services\DexScreenerService;

class SolanaChainAdapter implements ChainAdapter
{
    public function __construct(private DexScreenerService $dexScreener) {}

    public function chain(): Chain
    {
        return Chain::Solana;
    }

    public function marketData(string $address): array
    {
        return $this->dexScreener->analyzeToken($address, $this->chain()->value);
    }

    public function latestProfiles(int $limit = 20): array
    {
        return $this->dexScreener->latestProfiles($this->chain()->value, $limit);
    }

    public function unavailableSecurityChecks(): array
    {
        return [];
    }
}
