<?php

namespace App\Services;

use App\Chain;
use App\Models\TokenScan;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class SolanaTokenMetadataService
{
    private const MAX_SPL_DECIMALS = 18;

    public function __construct(
        private SolanaService $solana,
        private SolanaWalletConnectionService $wallets,
    ) {}

    /** @return array{mint: string, symbol: ?string, decimals: int} */
    public function resolve(string $mint): array
    {
        $metadata = $this->stored($mint);

        if ($metadata['decimals'] === null) {
            $metadata['decimals'] = $this->cachedRpcDecimals($metadata['mint']);
        }

        return $metadata;
    }

    /** @return array{mint: string, symbol: ?string, decimals: ?int} */
    public function stored(string $mint): array
    {
        $mint = trim($mint);

        if (! $this->wallets->isValidAddress($mint)) {
            throw new RuntimeException('The Solana token mint is invalid.');
        }

        $scan = TokenScan::query()
            ->where('chain', Chain::Solana->value)
            ->where('address', $mint)
            ->latest('last_scanned_at')
            ->latest('id')
            ->first();

        $symbol = $this->validSymbol($scan?->symbol);
        $decimals = data_get($scan?->raw_data, 'decimals');

        return [
            'mint' => $mint,
            'symbol' => $symbol,
            'decimals' => $this->validDecimals($decimals) ? $decimals : null,
        ];
    }

    private function cachedRpcDecimals(string $mint): int
    {
        $seconds = max(60, (int) config('services.solana.token_metadata_cache_seconds', 86400));

        return $this->cache()->remember(
            'solana-token-decimals.'.hash('sha256', $mint),
            now()->addSeconds($seconds),
            function () use ($mint): int {
                try {
                    $decimals = data_get($this->solana->getTokenSupply($mint), 'decimals');
                } catch (ConnectionException|RuntimeException $exception) {
                    throw new RuntimeException('Solana token metadata is temporarily unavailable.', previous: $exception);
                }

                if (! $this->validDecimals($decimals)) {
                    throw new RuntimeException('Solana RPC returned invalid token metadata.');
                }

                return $decimals;
            },
        );
    }

    private function cache(): Repository
    {
        return Cache::store((string) config('services.solana.metadata_cache_store', 'file'));
    }

    private function validDecimals(mixed $decimals): bool
    {
        return is_int($decimals) && $decimals >= 0 && $decimals <= self::MAX_SPL_DECIMALS;
    }

    private function validSymbol(mixed $symbol): ?string
    {
        if (! is_string($symbol) || trim($symbol) === '' || mb_strlen(trim($symbol)) > 32) {
            return null;
        }

        return trim($symbol);
    }
}
