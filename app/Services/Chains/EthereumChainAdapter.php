<?php

namespace App\Services\Chains;

use App\Chain;
use App\Services\DexScreenerService;
use App\Services\GeckoTerminalService;

class EthereumChainAdapter implements ChainAdapter
{
    public function __construct(
        private DexScreenerService $dexScreener,
        private GeckoTerminalService $geckoTerminal,
    ) {}

    public function chain(): Chain
    {
        return Chain::Ethereum;
    }

    public function marketData(string $address): array
    {
        /*
         * GeckoTerminal is discovery only.
         *
         * Keep DexScreener as our market-data validator so the
         * existing scanner qualification logic remains unchanged.
         */
        return $this->dexScreener->analyzeToken(
            $address,
            $this->chain()->value
        );
    }

    public function marketDataMany(array $addresses): array
    {
        return $this->dexScreener->analyzeTokens($addresses, $this->chain()->value);
    }

    public function latestProfiles(int $limit = 20): array
    {
        return $this->geckoTerminal->latestEthereumTokens($limit);
    }

    public function unavailableSecurityChecks(): array
    {
        return [
            'Birdeye Solana overview and holder data',
            'GoPlus Solana token-security evaluation',
            'Solana holder concentration',
            'Solana developer-sale analysis',
            'Pump.fun activity analysis',
        ];
    }
}
