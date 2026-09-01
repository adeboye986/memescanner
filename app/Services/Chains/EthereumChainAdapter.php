<?php

namespace App\Services\Chains;

use App\Chain;
use App\Services\DexScreenerService;

class EthereumChainAdapter implements ChainAdapter
{
    public function __construct(private DexScreenerService $dexScreener) {}

    public function chain(): Chain
    {
        return Chain::Ethereum;
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
        return ['Birdeye Solana overview and holder data', 'GoPlus Solana token-security evaluation', 'Solana holder concentration', 'Solana developer-sale analysis', 'Pump.fun activity analysis'];
    }
}
