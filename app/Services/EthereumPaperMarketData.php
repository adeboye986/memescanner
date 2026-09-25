<?php

namespace App\Services;

use App\Chain;
use App\Models\PaperPosition;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/** PAPER observations only: scanner qualification and LIVE validation retain their own contracts. */
class EthereumPaperMarketData
{
    public function __construct(private DexScreenerService $dex, private GeckoTerminalService $gecko) {}

    public static function poolId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^0x(?:[a-f0-9]{40}|[a-f0-9]{64})$/iD', $value)
            ? strtolower($value) : null;
    }

    public static function tokenAddress(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^0x[a-f0-9]{40}$/iD', $value) ? strtolower($value) : null;
    }

    /** @param list<PaperPosition> $positions @return array{observations: array, requests: int, failures: int, rate_limited: bool, provider_errors: list<array{provider: string, target_type: string, http_status: ?int, category: string}>} */
    public function fetch(array $positions, ?Closure $heartbeat = null, ?Closure $observe = null): array
    {
        $result = ['observations' => [], 'requests' => 0, 'failures' => 0, 'rate_limited' => false, 'provider_errors' => []];
        $budget = max(1, (int) config('services.trading.paper_market.ethereum.work_budget_seconds', 20));
        $started = hrtime(true);
        $deadline = now()->addSeconds($budget);
        $timeout = fn (): int => max(0, min(8, (int) floor(min(
            $budget - (hrtime(true) - $started) / 1_000_000_000,
            now()->diffInSeconds($deadline, false),
        ))));
        $pending = [];
        $candidates = [];
        foreach ($positions as $position) {
            $pending[$position->id] = $position;
            $candidates[$position->id] = [];
        }
        $deliver = function (PaperPosition $position, ?array $selected, array $seen, string $reason = 'no_valid_provider_observation') use (&$result, &$pending, $observe): void {
            $original = self::poolId(data_get($position->meta, 'pair_address'));
            $originalObservation = collect($seen)->first(fn (array $pair): bool => $pair['pair_address'] === $original);
            $data = $selected === null ? ['available' => false, 'reason' => $reason] : [
                ...$selected, 'original_pair_address' => $original,
                'original_liquidity_usd' => $originalObservation['liquidity_usd'] ?? null,
                'minimum_liquidity_usd' => $this->minimumLiquidity(),
                'pool_switched' => $original !== null && $selected['pair_address'] !== $original,
                'selection_reason' => $original === $selected['pair_address'] ? 'original_pool'
                    : ($originalObservation !== null ? 'original_pool_not_usable' : ($original ? 'original_pool_unavailable_in_primary_response' : 'highest_liquidity')),
                'fallback_reason' => $selected['provider'] === 'geckoterminal' ? 'no_usable_dexscreener_observation' : null,
                'pool_execution_limitation' => 'Pool marks do not prove that this position could be sold in the selected pool.',
            ];
            $result['observations'][$position->id] = $data;
            unset($pending[$position->id]);
            $observe?->__invoke($position, $data);
        };
        $addresses = array_values(array_unique(array_filter(array_map(fn ($p) => $p->chain === Chain::Ethereum ? self::tokenAddress($p->address) : null, $positions))));
        foreach (array_chunk($addresses, 30) as $chunk) {
            $heartbeat?->__invoke();
            if (($seconds = $timeout()) < 1) {
                break;
            }
            $result['requests']++;
            try {
                $pairs = $this->dex->paperEthereumPairs($chunk, $seconds);
                $fetched = now()->toIso8601String();
            } catch (Throwable $exception) {
                $result['provider_errors'][] = $this->providerError('dexscreener', 'token_batch', $exception);
                $result['failures']++;
                $result['rate_limited'] = $result['rate_limited'] || str_contains($exception->getMessage(), '429');

                continue;
            }
            foreach ($pending as $position) {
                $address = self::tokenAddress($position->address);
                if ($position->chain !== Chain::Ethereum || ! in_array($address, $chunk, true)) {
                    continue;
                }
                foreach ($pairs as $pair) {
                    if (is_array($pair) && ($normalized = $this->dexPair([...$pair, '_paper_fetched_at' => $fetched], $address)) !== null) {
                        $candidates[$position->id][] = $normalized;
                    }
                }
                $selected = $this->select($candidates[$position->id], self::poolId(data_get($position->meta, 'pair_address')), $position);
                if ($selected !== null) {
                    $deliver($position, $selected, $candidates[$position->id]);
                }
            }
        }
        $fallbacks = [];
        $geckoRateLimited = false;
        foreach ($pending as $position) {
            $heartbeat?->__invoke();
            $address = $position->chain === Chain::Ethereum ? self::tokenAddress($position->address) : null;
            $original = self::poolId(data_get($position->meta, 'pair_address'));
            $seen = $candidates[$position->id];
            $selected = null;
            if ($address !== null && ! $geckoRateLimited) {
                foreach (array_filter([$original, 'token_pools']) as $target) {
                    $heartbeat?->__invoke();
                    $key = $address.':'.$target;
                    if (! array_key_exists($key, $fallbacks)) {
                        if (($seconds = $timeout()) < 1) {
                            break;
                        }
                        $result['requests']++;
                        $fallbacks[$key] = [];
                        try {
                            $pools = $this->gecko->ethereumPaperPools($address, $target === 'token_pools' ? null : $target, $seconds);
                            foreach ($pools as $pool) {
                                $normalized = $this->geckoPool($pool, $address);
                                if ($normalized !== null && ($target === 'token_pools' || $normalized['pair_address'] === $target)) {
                                    $fallbacks[$key][] = $normalized;
                                }
                            }
                        } catch (Throwable $exception) {
                            $result['provider_errors'][] = $this->providerError('geckoterminal', $target === 'token_pools' ? 'token_pools' : 'original_pool', $exception);
                            $result['failures']++;
                            $geckoRateLimited = str_contains($exception->getMessage(), '429');
                            $result['rate_limited'] = $result['rate_limited'] || $geckoRateLimited;
                        }
                    }
                    $seen = [...$seen, ...$fallbacks[$key]];
                    $selected = $this->select($seen, $original, $position);
                    if ($selected !== null || $geckoRateLimited) {
                        break;
                    }
                }
            }
            /** Retain a rejected mark for diagnostics without claiming simulation eligibility. */
            $diagnostic = $selected ?? (collect($seen)->first(fn (array $pair): bool => $pair['pair_address'] === $original) ?? ($seen[0] ?? null));
            $deliver($position, $diagnostic, $seen, $address === null ? 'invalid_token_identity'
                : ($timeout() < 1 ? 'provider_work_budget_exhausted' : 'no_valid_provider_observation'));
        }

        return $result;
    }

    /** @return array{provider: string, target_type: string, http_status: ?int, category: string} */
    private function providerError(string $provider, string $targetType, Throwable $exception): array
    {
        $name = $provider === 'dexscreener' ? 'DexScreener' : 'GeckoTerminal';
        $message = $exception->getMessage();
        /** Only recognize the exact status-only messages emitted by our provider wrappers. */
        $status = preg_match('/\A'.$name.' PAPER API error: ([1-5][0-9]{2})\z/', $message, $matches) === 1
            ? (int) $matches[1] : null;

        return [
            'provider' => $provider,
            'target_type' => $targetType,
            'http_status' => $status,
            'category' => match (true) {
                $status !== null => 'http_error',
                $exception instanceof ConnectionException => 'connection_error',
                $message === 'Malformed '.$name.' PAPER response.' => 'malformed_response',
                default => 'provider_request_failed',
            },
        ];
    }

    private function minimumLiquidity(): float
    {
        return max(0, (float) config('services.trading.paper_market.ethereum.minimum_liquidity_usd', 0));
    }

    private function select(array $pairs, ?string $original, PaperPosition $position): ?array
    {
        $pairs = array_values(array_filter($pairs, fn (array $pair): bool => $pair['liquidity_usd'] !== null
            && $pair['liquidity_usd'] >= $this->minimumLiquidity()
            && ($pair['market_cap'] !== null || ($pair['price_usd'] !== null && PaperMarketObservation::positive($position->entry_price) !== null))));
        foreach ($pairs as $pair) {
            if ($pair['pair_address'] === $original) {
                return $pair;
            }
        }
        usort($pairs, fn ($a, $b) => $b['liquidity_usd'] <=> $a['liquidity_usd']);

        return $pairs[0] ?? null;
    }

    private function dexPair(mixed $pair, string $token): ?array
    {
        if (! is_array($pair) || ($pair['chainId'] ?? null) !== 'ethereum'
            || self::tokenAddress(data_get($pair, 'baseToken.address')) !== $token
            || self::tokenAddress(data_get($pair, 'quoteToken.address')) === null
            || ($id = self::poolId($pair['pairAddress'] ?? null)) === null) {
            return null;
        }

        $observation = $this->observation('dexscreener', $token, $id, $pair['priceUsd'] ?? null, $pair['marketCap'] ?? null,
            data_get($pair, 'liquidity.usd'), $pair['fdv'] ?? null);

        return $observation === null ? null : [...$observation, 'fetched_at' => $pair['_paper_fetched_at']];
    }

    private function geckoPool(mixed $pool, string $token): ?array
    {
        if (! is_array($pool) || ($pool['type'] ?? null) !== 'pool'
            || ($id = self::poolId(data_get($pool, 'attributes.address'))) === null
            || ! is_string($pool['id'] ?? null) || strtolower($pool['id']) !== 'eth_'.$id) {
            return null;
        }
        $base = $this->relationshipToken($pool, 'base_token');
        $quote = $this->relationshipToken($pool, 'quote_token');
        if ($base === null || $quote === null || $base === $quote || ! in_array($token, [$base, $quote], true)) {
            return null;
        }
        $isBase = $base === $token;
        $observation = $this->observation('geckoterminal', $token, $id,
            data_get($pool, $isBase ? 'attributes.base_token_price_usd' : 'attributes.quote_token_price_usd'),
            $isBase ? data_get($pool, 'attributes.market_cap_usd') : null,
            data_get($pool, 'attributes.reserve_in_usd'), $isBase ? data_get($pool, 'attributes.fdv_usd') : null);

        return $observation === null ? null : [...$observation, 'requested_token_is_base' => $isBase,
            'requested_token_identity_verified' => true, 'price_token_side' => $isBase ? 'base' : 'quote'];
    }

    private function relationshipToken(array $pool, string $side): ?string
    {
        $id = data_get($pool, 'relationships.'.$side.'.data.id');
        if (data_get($pool, 'relationships.'.$side.'.data.type') !== 'token'
            || ! is_string($id) || ! preg_match('/^eth_0x[a-f0-9]{40}$/iD', $id)) {
            return null;
        }

        return self::tokenAddress(substr($id, 4));
    }

    private function observation(string $provider, string $token, string $pool, mixed $price, mixed $cap, mixed $liquidity, mixed $fdv): ?array
    {
        $price = PaperMarketObservation::positive($price);
        $cap = PaperMarketObservation::positive($cap);
        if ($price === null && $cap === null) {
            return null;
        }

        return ['available' => true, 'provider' => $provider, 'requested_token_is_base' => true,
            'requested_token_address' => $token, 'pair_address' => $pool, 'price_usd' => $price, 'market_cap' => $cap,
            'liquidity_usd' => PaperMarketObservation::positive($liquidity), 'fdv' => PaperMarketObservation::positive($fdv),
            'fetched_at' => now()->toIso8601String(), 'provider_observed_at' => null];
    }
}
