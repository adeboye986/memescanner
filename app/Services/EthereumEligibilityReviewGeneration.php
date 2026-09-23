<?php

namespace App\Services;

use Closure;
use DomainException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Authoritative state is never reconstructed from a browser or session snapshot. */
class EthereumEligibilityReviewGeneration
{
    public function begin(int $actor, string $token, string $submission): string
    {
        return $this->locked($actor, $token, $submission, function (Repository $cache, string $key): string {
            if ($cache->has($key)) {
                throw new DomainException('Review submission already has an authoritative generation.');
            }

            return $this->write($cache, $key);
        });
    }

    public function replace(int $actor, string $token, string $submission, string $generation): string
    {
        return $this->locked($actor, $token, $submission, function (Repository $cache, string $key) use ($generation): string {
            $this->current($cache, $key, $generation);

            return $this->write($cache, $key);
        });
    }

    public function bind(int $actor, string $token, string $submission, string $generation, string $reference): void
    {
        $this->locked($actor, $token, $submission, function (Repository $cache, string $key) use ($generation, $reference): void {
            $state = $this->current($cache, $key, $generation);
            if ($state['reference'] !== null) {
                throw new DomainException('Review generation already has an observation.');
            }
            $state['reference'] = $reference;
            if (! $cache->put($key, $state, max(1, $state['expires'] - now()->timestamp))) {
                throw new DomainException('Cannot persist authoritative review evidence.');
            }
        });
    }

    /** Hold the same lock as replacement until publication has committed. No RPC inside this callback. */
    public function withCurrent(int $actor, string $token, string $submission, string $generation, ?string $reference, Closure $publish): mixed
    {
        return $this->locked($actor, $token, $submission, function (Repository $cache, string $key) use ($generation, $reference, $publish): mixed {
            $state = $this->current($cache, $key, $generation);
            if ($reference !== null && $state['reference'] !== $reference) {
                throw new DomainException('Stale review observation. Collect evidence and confirm again.');
            }

            return $publish();
        });
    }

    /** @return array{generation: string, reference: ?string, expires: int} */
    private function current(Repository $cache, string $key, string $generation): array
    {
        $state = $cache->get($key);
        if (! is_array($state) || ($state['generation'] ?? null) !== $generation || ($state['expires'] ?? 0) <= now()->timestamp) {
            throw new DomainException('Stale or expired review generation. Open the review again.');
        }

        return $state;
    }

    private function write(Repository $cache, string $key): string
    {
        $generation = (string) Str::uuid();
        if (! $cache->put($key, ['generation' => $generation, 'reference' => null, 'expires' => now()->addMinutes(20)->timestamp], 1200)) {
            throw new DomainException('Cannot persist authoritative review generation.');
        }

        return $generation;
    }

    private function locked(int $actor, string $token, string $submission, Closure $operation): mixed
    {
        $cache = Cache::store(config('services.ethereum.metadata_cache_store', 'file'));
        $store = $cache->getStore();
        if (! ($store instanceof FileStore || $store instanceof RedisStore || ($store instanceof ArrayStore && app()->runningUnitTests()))) {
            throw new DomainException('Review generations require a shared file or Redis cache with non-expiring atomic locks.');
        }
        $key = 'ethereum-review-generation.'.hash('sha256', $actor.'|'.EthereumAccountingNumbers::address($token).'|'.$submission);
        // A finite lease could expire during a database commit and permit stale publication.
        $lock = $store->lock($key.'.lock', 0);
        if (! $lock->get()) {
            throw new DomainException('Review generation is busy. Retry after the other operation finishes.');
        }
        try {
            return $operation($cache, $key);
        } finally {
            $lock->release();
        }
    }
}
