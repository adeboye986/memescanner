<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeckoTerminalService
{
    protected string $baseUrl = 'https://api.geckoterminal.com/api/v2';

    /**
     * Return recently created Ethereum token addresses in the
     * same minimal shape expected by EthereumScannerService.
     *
     * @return array<int, array{chainId: string, tokenAddress: string}>
     */
    public function latestEthereumTokens(int $limit = 30): array
    {
        $response = Http::connectTimeout(10)
            ->timeout(30)
            ->acceptJson()
            ->get($this->baseUrl.'/networks/eth/new_pools', [
                'page' => 1,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'GeckoTerminal new pools API error: '.
                $response->status().' - '.
                $response->body()
            );
        }

        $pools = $response->json('data');

        if (! is_array($pools)) {
            return [];
        }

        $tokens = [];
        $seen = [];

        foreach ($pools as $pool) {
            /*
             * GeckoTerminal exposes token relationships using IDs
             * such as:
             *
             * eth_0x...
             */
            $baseTokenId = data_get(
                $pool,
                'relationships.base_token.data.id'
            );

            if (! is_string($baseTokenId) || $baseTokenId === '') {
                continue;
            }

            $address = $this->extractEthereumAddress($baseTokenId);

            if ($address === null) {
                continue;
            }

            $address = strtolower($address);

            if (isset($seen[$address])) {
                continue;
            }

            $seen[$address] = true;

            $tokens[] = [
                'chainId' => 'ethereum',
                'tokenAddress' => $address,
            ];

            if (count($tokens) >= $limit) {
                break;
            }
        }

        return $tokens;
    }

    /** PAPER-only fallback. Pool identifiers may be EVM addresses or Uniswap V4 bytes32 IDs. */
    public function ethereumPaperPools(string $token, ?string $pool = null, int $timeout = 8): array
    {
        if (EthereumPaperMarketData::tokenAddress($token) === null
            || ($pool !== null && EthereumPaperMarketData::poolId($pool) === null)) {
            throw new RuntimeException('Invalid Ethereum PAPER market identity.');
        }
        $path = $pool === null ? '/tokens/'.$token.'/pools' : '/pools/'.$pool;
        $response = Http::connectTimeout(min(3, $timeout))->timeout($timeout)->acceptJson()->get($this->baseUrl.'/networks/eth'.$path);
        if (! $response->successful()) {
            throw new RuntimeException('GeckoTerminal PAPER API error: '.$response->status());
        }
        $data = $response->json('data');
        if (! is_array($data) || ($pool === null && ! array_is_list($data)) || ($pool !== null && array_is_list($data))) {
            throw new RuntimeException('Malformed GeckoTerminal PAPER response.');
        }

        return $pool === null ? $data : [$data];
    }

    private function extractEthereumAddress(string $id): ?string
    {
        if (preg_match('/0x[a-fA-F0-9]{40}/', $id, $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }
}
