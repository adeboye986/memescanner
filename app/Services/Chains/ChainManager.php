<?php

namespace App\Services\Chains;

use App\Chain;

class ChainManager
{
    public function __construct(private SolanaChainAdapter $solana, private EthereumChainAdapter $ethereum) {}

    public function for(Chain|string $chain): ChainAdapter
    {
        $resolved = $chain instanceof Chain ? $chain : Chain::fromInput($chain);

        return match ($resolved) {
            Chain::Solana => $this->solana,
            Chain::Ethereum => $this->ethereum,
        };
    }
}
