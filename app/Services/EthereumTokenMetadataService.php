<?php

namespace App\Services;

use App\Chain;
use App\Models\TokenScan;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class EthereumTokenMetadataService
{
    private const MAX_ERC20_DECIMALS = 18;

    private const DECIMALS_SELECTOR = '0x313ce567';

    private const SYMBOL_SELECTOR = '0x95d89b41';

    public function __construct(
        private EthereumService $ethereum,
    ) {}

    /**
     * @return array{
     *     address: string,
     *     symbol: ?string,
     *     decimals: int
     * }
     */
    public function resolve(string $address): array
    {
        $address = strtolower(trim($address));

        if (! $this->validAddress($address)) {
            throw new RuntimeException('The Ethereum token address is invalid.');
        }

        $stored = $this->stored($address);

        $decimals = $this->cachedDecimals($address);

        $symbol = $stored['symbol'];

        if ($symbol === null) {
            try {
                $symbol = $this->cachedSymbol($address);
            } catch (RuntimeException) {
                $symbol = null;
            }
        }

        return [
            'address' => $address,
            'symbol' => $symbol,
            'decimals' => $decimals,
        ];
    }

    /**
     * @return array{
     *     address: string,
     *     symbol: ?string
     * }
     */
    public function stored(string $address): array
    {
        $address = strtolower(trim($address));

        if (! $this->validAddress($address)) {
            throw new RuntimeException('The Ethereum token address is invalid.');
        }

        $scan = TokenScan::query()
            ->where('chain', Chain::Ethereum->value)
            ->whereRaw('LOWER(address) = ?', [$address])
            ->latest('last_scanned_at')
            ->latest('id')
            ->first();

        return [
            'address' => $address,
            'symbol' => $this->validSymbol($scan?->symbol),
        ];
    }

    private function cachedDecimals(string $address): int
    {
        return $this->cache()->remember(
            'ethereum-token-decimals.'.hash('sha256', $address),
            now()->addSeconds($this->cacheSeconds()),
            function () use ($address): int {
                try {
                    $result = $this->ethereum->call($address, self::DECIMALS_SELECTOR);
                } catch (RuntimeException $exception) {
                    throw new RuntimeException(
                        'Ethereum token metadata is temporarily unavailable.',
                        previous: $exception,
                    );
                }

                $decimals = $this->decodeUint256($result);

                if ($decimals < 0 || $decimals > self::MAX_ERC20_DECIMALS) {
                    throw new RuntimeException('Ethereum RPC returned invalid token metadata.');
                }

                return $decimals;
            },
        );
    }

    private function cachedSymbol(string $address): ?string
    {
        return $this->cache()->remember(
            'ethereum-token-symbol.'.hash('sha256', $address),
            now()->addSeconds($this->cacheSeconds()),
            function () use ($address): ?string {
                try {
                    $result = $this->ethereum->call($address, self::SYMBOL_SELECTOR);
                } catch (RuntimeException $exception) {
                    throw new RuntimeException(
                        'Ethereum token metadata is temporarily unavailable.',
                        previous: $exception,
                    );
                }

                return $this->decodeSymbol($result);
            },
        );
    }

    private function decodeUint256(string $hex): int
    {
        $hex = strtolower($hex);

        if (preg_match('/^0x[0-9a-f]{64}$/', $hex) !== 1) {
            throw new RuntimeException('Ethereum RPC returned invalid token metadata.');
        }

        $value = ltrim(substr($hex, 2), '0');

        if ($value === '') {
            return 0;
        }

        if (strlen($value) > 2) {
            throw new RuntimeException('Ethereum RPC returned invalid token metadata.');
        }

        return hexdec($value);
    }

    private function decodeSymbol(string $hex): ?string
    {
        if (preg_match('/^0x[0-9a-fA-F]*$/', $hex) !== 1) {
            throw new RuntimeException('Ethereum RPC returned invalid token metadata.');
        }

        $raw = substr($hex, 2);

        if ($raw === '') {
            return null;
        }

        /*
         * Standard ABI string:
         * offset (32 bytes)
         * length (32 bytes)
         * UTF-8 bytes padded to 32-byte boundary
         */
        if (strlen($raw) >= 128) {
            $offset = substr($raw, 0, 64);

            if ($offset === str_pad(dechex(32), 64, '0', STR_PAD_LEFT)) {
                $lengthHex = substr($raw, 64, 64);
                $length = hexdec(ltrim($lengthHex, '0') ?: '0');

                if ($length >= 1 && $length <= 32) {
                    $symbolHex = substr($raw, 128, $length * 2);

                    if (strlen($symbolHex) === $length * 2) {
                        return $this->validSymbol(hex2bin($symbolHex));
                    }
                }
            }
        }

        /*
         * Some older ERC-20 contracts return bytes32 rather than string.
         */
        if (strlen($raw) === 64) {
            $binary = hex2bin($raw);

            if ($binary !== false) {
                return $this->validSymbol(rtrim($binary, "\0"));
            }
        }

        return null;
    }

    private function validSymbol(mixed $symbol): ?string
    {
        if (! is_string($symbol)) {
            return null;
        }

        $symbol = trim($symbol);

        if ($symbol === ''
            || mb_strlen($symbol) > 32
            || preg_match('/^[\pL\pN._+\-$ ]+$/u', $symbol) !== 1) {
            return null;
        }

        return $symbol;
    }

    private function validAddress(string $address): bool
    {
        return preg_match('/^0x[a-f0-9]{40}$/', $address) === 1;
    }

    private function cacheSeconds(): int
    {
        return max(
            60,
            (int) config('services.ethereum.token_metadata_cache_seconds', 86400),
        );
    }

    private function cache(): Repository
    {
        return Cache::store(
            (string) config('services.ethereum.metadata_cache_store', 'file'),
        );
    }
}
