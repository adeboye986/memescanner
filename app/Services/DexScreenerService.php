<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DexScreenerService
{
    protected string $baseUrl = 'https://api.dexscreener.com';

    public function latestSolanaProfiles(
        int $limit = 20
    ): array {
        return $this->latestProfiles('solana', $limit);
    }

    public function latestProfiles(string $chainId, int $limit = 20): array
    {
        $response = Http::connectTimeout(10)
            ->timeout(30)
            ->acceptJson()
            ->get(
                $this->baseUrl.
                '/token-profiles/latest/v1'
            );

        if (! $response->successful()) {
            throw new RuntimeException(
                'DexScreener profiles API error: '.
                $response->status().
                ' - '.
                $response->body()
            );
        }

        $profiles = $response->json();

        if (! is_array($profiles)) {
            return [];
        }

        /*
        * Only Solana tokens with an address.
        */
        $chainProfiles = array_values(
            array_filter(
                $profiles,
                function ($profile) use ($chainId) {
                    return
                        ($profile['chainId'] ?? null)
                            === $chainId &&
                        ! empty(
                            $profile['tokenAddress']
                        );
                }
            )
        );

        /*
        * Prevent duplicate addresses.
        */
        $seen = [];

        $chainProfiles = array_values(
            array_filter(
                $chainProfiles,
                function ($profile) use (&$seen) {
                    $address =
                        $profile['tokenAddress'];

                    if (isset($seen[$address])) {
                        return false;
                    }

                    $seen[$address] = true;

                    return true;
                }
            )
        );

        return array_slice(
            $chainProfiles,
            0,
            max(1, min($limit, 50))
        );
    }

    public function tokenPairs(string $address, string $chainId = 'solana'): array
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::connectTimeout(10)
                    ->timeout(30)
                    ->acceptJson()
                    ->get(
                        $this->baseUrl.
                        "/token-pairs/v1/{$chainId}/".
                        $address
                    );

                if ($response->successful()) {
                    return $response->json();
                }

                if (
                    $response->status() === 429 &&
                    $attempt < $maxAttempts
                ) {
                    sleep(3 * $attempt);

                    continue;
                }

                throw new RuntimeException(
                    'DexScreener API error: '.
                    $response->status().
                    ' - '.
                    $response->body()
                );

            } catch (
                ConnectionException $e
            ) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                sleep(2 * $attempt);
            }
        }

        throw new RuntimeException(
            'DexScreener request failed after retries.'
        );
    }

    public function bestPair(string $address, string $chainId = 'solana'): ?array
    {
        $pairs = $this->tokenPairs($address, $chainId);

        if (empty($pairs)) {
            return null;
        }

        /*
         * IMPORTANT:
         *
         * DexScreener's priceUsd, marketCap and FDV describe
         * the BASE token of the pair.
         *
         * So first prefer pools where the requested token
         * is actually the base token.
         *
         * Solana addresses are case-sensitive, therefore
         * this must remain an exact comparison.
         */
        $basePairs = array_values(
            array_filter(
                $pairs,
                function ($pair) use ($address, $chainId) {
                    return $this->addressesEqual(
                        (string) ($pair['baseToken']['address'] ?? ''),
                        $address,
                        $chainId,
                    );
                }
            )
        );

        /*
         * If the requested token appears as the base token
         * in at least one pool, only consider those pools.
         *
         * Otherwise fall back to all pairs so we can still
         * obtain pair/liquidity metadata.
         */
        $candidatePairs =
            ! empty($basePairs)
                ? $basePairs
                : $pairs;

        /*
         * Choose the highest-liquidity pool among the
         * eligible candidates.
         */
        usort(
            $candidatePairs,
            function ($a, $b) {
                $aLiquidity =
                    (float) (
                        $a['liquidity']['usd'] ?? 0
                    );

                $bLiquidity =
                    (float) (
                        $b['liquidity']['usd'] ?? 0
                    );

                return $bLiquidity <=> $aLiquidity;
            }
        );

        return $candidatePairs[0] ?? null;
    }

    public function analyzeToken(string $address, string $chainId = 'solana'): array
    {
        $pair = $this->bestPair($address, $chainId);

        if (! $pair) {
            return [
                'available' => false,
                'pair' => null,
                'requested_token_is_base' => false,
            ];
        }

        /*
         * Check whether the requested token is the base
         * token of the selected pair.
         */
        $requestedTokenIsBase = $this->addressesEqual(
            (string) ($pair['baseToken']['address'] ?? ''),
            $address,
            $chainId,
        );

        $createdAt =
            $pair['pairCreatedAt'] ?? null;

        $pairAgeMinutes = null;

        if ($createdAt) {
            $pairAgeMinutes = max(
                0,
                (int) floor(
                    (
                        now()->timestamp -
                        ($createdAt / 1000)
                    ) / 60
                )
            );
        }

        /*
         * IMPORTANT:
         *
         * priceUsd, marketCap and FDV are only trusted
         * when our requested token is the BASE token.
         *
         * If it is the quote token, returning those values
         * would risk assigning the other token's market cap
         * to our token.
         */
        $priceUsd = null;
        $marketCap = null;
        $fdv = null;

        if ($requestedTokenIsBase) {
            $priceUsd =
                isset($pair['priceUsd'])
                    ? (float) $pair['priceUsd']
                    : null;

            $marketCap =
                isset($pair['marketCap'])
                    ? (float) $pair['marketCap']
                    : null;

            $fdv =
                isset($pair['fdv'])
                    ? (float) $pair['fdv']
                    : null;
        }

        return [
            'available' => true,

            /*
             * Very important for downstream checks.
             */
            'requested_token_is_base' => $requestedTokenIsBase,

            'dex' => $pair['dexId'] ?? null,

            'pair_address' => $pair['pairAddress'] ?? null,

            /*
             * Requested token identity.
             */
            'requested_token_address' => $address,

            /*
             * Pair identities for debugging.
             */
            'base_token_address' => $pair['baseToken']['address'] ?? null,

            'base_token_symbol' => $pair['baseToken']['symbol'] ?? null,

            'quote_token_address' => $pair['quoteToken']['address'] ?? null,

            'quote_token_symbol' => $pair['quoteToken']['symbol'] ?? null,

            /*
             * Only trusted when requested_token_is_base=true.
             */
            'price_usd' => $priceUsd,

            'market_cap' => $marketCap,

            'fdv' => $fdv,

            /*
             * Pair-level data is still useful even if the
             * requested token is the quote token.
             */
            'liquidity_usd' => isset($pair['liquidity']['usd'])
                    ? (float) $pair['liquidity']['usd']
                    : null,

            'buys_5m' => (int) (
                $pair['txns']['m5']['buys'] ?? 0
            ),

            'sells_5m' => (int) (
                $pair['txns']['m5']['sells'] ?? 0
            ),

            'volume_5m' => (float) (
                $pair['volume']['m5'] ?? 0
            ),

            /*
             * This is only directly meaningful for the
             * selected pair/base token, so downstream code
             * should check requested_token_is_base first
             * before treating it as our token's movement.
             */
            'price_change_5m' => $requestedTokenIsBase &&
                isset($pair['priceChange']['m5'])
                    ? (float) $pair['priceChange']['m5']
                    : null,

            'pair_created_at' => $createdAt,

            'pair_age_minutes' => $pairAgeMinutes,

            'url' => $pair['url'] ?? null,

            'raw' => $pair,
        ];
    }

    /**
     * Fetch up to 30 token addresses per documented DexScreener request.
     *
     * @param  list<string>  $addresses
     * @return array<string, array<string, mixed>>
     */
    public function analyzeTokens(array $addresses, string $chainId = 'solana'): array
    {
        $results = [];

        foreach (array_chunk(array_values(array_unique($addresses)), 30) as $chunk) {
            $response = Http::connectTimeout(3)
                ->timeout(8)
                ->acceptJson()
                ->get($this->baseUrl.'/tokens/v1/'.$chainId.'/'.implode(',', $chunk));

            if (! $response->successful()) {
                throw new RuntimeException(
                    'DexScreener batch API error: '.$response->status().' - '.$response->body()
                );
            }

            $pairs = $response->json();

            if (! is_array($pairs)) {
                throw new RuntimeException('DexScreener batch API returned malformed JSON.');
            }

            foreach ($chunk as $address) {
                $eligible = array_values(array_filter(
                    $pairs,
                    fn (mixed $pair): bool => is_array($pair)
                        && $this->addressesEqual((string) data_get($pair, 'baseToken.address'), $address, $chainId),
                ));

                usort($eligible, fn (array $left, array $right): int => (float) data_get($right, 'liquidity.usd', 0) <=> (float) data_get($left, 'liquidity.usd', 0));

                $pair = $eligible[0] ?? null;
                $key = $chainId === 'ethereum' ? strtolower($address) : $address;

                $results[$key] = $pair
                    ? [
                        'available' => true,
                        'requested_token_is_base' => true,
                        'dex' => $pair['dexId'] ?? null,
                        'pair_address' => $pair['pairAddress'] ?? null,
                        'requested_token_address' => $address,
                        'base_token_address' => data_get($pair, 'baseToken.address'),
                        'base_token_symbol' => data_get($pair, 'baseToken.symbol'),
                        'quote_token_address' => data_get($pair, 'quoteToken.address'),
                        'quote_token_symbol' => data_get($pair, 'quoteToken.symbol'),
                        'price_usd' => isset($pair['priceUsd']) ? (float) $pair['priceUsd'] : null,
                        'market_cap' => isset($pair['marketCap']) ? (float) $pair['marketCap'] : null,
                        'fdv' => isset($pair['fdv']) ? (float) $pair['fdv'] : null,
                        'liquidity_usd' => isset($pair['liquidity']['usd']) ? (float) $pair['liquidity']['usd'] : null,
                        'raw' => $pair,
                    ]
                    : [
                        'available' => false,
                        'pair' => null,
                        'requested_token_is_base' => false,
                    ];
            }
        }

        return $results;
    }

    public function paidOrders(
        string $tokenAddress,
        string $chainId = 'solana'
    ): array {
        $response = Http::connectTimeout(10)
            ->timeout(20)
            ->retry(
                2,
                500,
                function ($exception, $request) {
                    return $exception instanceof ConnectionException;
                }
            )
            ->get(
                "https://api.dexscreener.com/orders/v1/{$chainId}/{$tokenAddress}"
            );

        $response->throw();

        $data = $response->json();

        $orders = $data['orders'] ?? [];

        if (! is_array($orders)) {
            $orders = [];
        }

        $paidOrders = array_values(
            array_filter(
                $orders,
                function (array $order) {
                    return in_array(
                        $order['status'] ?? null,
                        [
                            'approved',
                            'processing',
                            'on-hold',
                        ],
                        true
                    );
                }
            )
        );

        return [
            'dex_paid' => ! empty($paidOrders),
            'orders' => $paidOrders,
            'all_orders' => $orders,
            'order_count' => count($paidOrders),
            'types' => array_values(
                array_unique(
                    array_filter(
                        array_column(
                            $paidOrders,
                            'type'
                        )
                    )
                )
            ),
        ];
    }

    private function addressesEqual(string $left, string $right, string $chainId): bool
    {
        return $chainId === 'ethereum'
            ? strtolower($left) === strtolower($right)
            : $left === $right;
    }
}
