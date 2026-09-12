<?php

namespace Tests\Unit;

use App\Chain;
use App\Models\TokenScan;
use App\Services\EthereumService;
use App\Services\EthereumTokenMetadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class EthereumTokenMetadataServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.ethereum.metadata_cache_store', 'array');
    }

    public function test_stored_symbol_and_rpc_decimals_are_resolved(): void
    {
        $this->createScan('USDC');

        $this->mock(EthereumService::class, function ($mock): void {
            $mock->shouldReceive('call')
                ->once()
                ->with(self::TOKEN, '0x313ce567')
                ->andReturn(
                    '0x0000000000000000000000000000000000000000000000000000000000000006'
                );
        });

        $metadata = app(EthereumTokenMetadataService::class)->resolve(self::TOKEN);

        $this->assertSame([
            'address' => self::TOKEN,
            'symbol' => 'USDC',
            'decimals' => 6,
        ], $metadata);
    }

    public function test_missing_symbol_falls_back_to_standard_erc20_symbol_call(): void
    {
        $this->mock(EthereumService::class, function ($mock): void {
            $mock->shouldReceive('call')
                ->once()
                ->with(self::TOKEN, '0x313ce567')
                ->andReturn(
                    '0x0000000000000000000000000000000000000000000000000000000000000006'
                );

            $mock->shouldReceive('call')
                ->once()
                ->with(self::TOKEN, '0x95d89b41')
                ->andReturn(
                    '0x'.
                    str_pad(dechex(32), 64, '0', STR_PAD_LEFT).
                    str_pad(dechex(4), 64, '0', STR_PAD_LEFT).
                    str_pad(bin2hex('USDC'), 64, '0', STR_PAD_RIGHT)
                );
        });

        $metadata = app(EthereumTokenMetadataService::class)->resolve(self::TOKEN);

        $this->assertSame('USDC', $metadata['symbol']);
        $this->assertSame(6, $metadata['decimals']);
    }

    public function test_bytes32_symbol_is_supported(): void
    {
        $this->mock(EthereumService::class, function ($mock): void {
            $mock->shouldReceive('call')
                ->once()
                ->with(self::TOKEN, '0x313ce567')
                ->andReturn(
                    '0x0000000000000000000000000000000000000000000000000000000000000006'
                );

            $mock->shouldReceive('call')
                ->once()
                ->with(self::TOKEN, '0x95d89b41')
                ->andReturn(
                    '0x'.str_pad(bin2hex('USDC'), 64, '0', STR_PAD_RIGHT)
                );
        });

        $metadata = app(EthereumTokenMetadataService::class)->resolve(self::TOKEN);

        $this->assertSame('USDC', $metadata['symbol']);
    }

    public function test_rpc_metadata_is_cached(): void
    {
        $service = $this->mock(EthereumService::class, function ($mock): void {
            $mock->shouldReceive('call')
                ->once()
                ->with(self::TOKEN, '0x313ce567')
                ->andReturn(
                    '0x0000000000000000000000000000000000000000000000000000000000000006'
                );

            $mock->shouldReceive('call')
                ->once()
                ->with(self::TOKEN, '0x95d89b41')
                ->andReturn(
                    '0x'.
                    str_pad(dechex(32), 64, '0', STR_PAD_LEFT).
                    str_pad(dechex(4), 64, '0', STR_PAD_LEFT).
                    str_pad(bin2hex('USDC'), 64, '0', STR_PAD_RIGHT)
                );
        });

        $metadataService = app(EthereumTokenMetadataService::class);

        $first = $metadataService->resolve(self::TOKEN);
        $second = $metadataService->resolve(self::TOKEN);

        $this->assertSame($first, $second);
        $this->assertSame(6, $second['decimals']);
        $this->assertSame('USDC', $second['symbol']);
    }

    public function test_invalid_decimals_are_rejected(): void
    {
        $this->mock(EthereumService::class, function ($mock): void {
            $mock->shouldReceive('call')
                ->once()
                ->with(self::TOKEN, '0x313ce567')
                ->andReturn(
                    '0x0000000000000000000000000000000000000000000000000000000000000013'
                );
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ethereum RPC returned invalid token metadata.');

        app(EthereumTokenMetadataService::class)->resolve(self::TOKEN);
    }

    public function test_invalid_address_is_rejected_without_rpc(): void
    {
        $this->mock(EthereumService::class, function ($mock): void {
            $mock->shouldNotReceive('call');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The Ethereum token address is invalid.');

        app(EthereumTokenMetadataService::class)->resolve('not-an-address');
    }

    public function test_symbol_failure_does_not_block_valid_decimals(): void
    {
        $this->mock(EthereumService::class, function ($mock): void {
            $mock->shouldReceive('call')
                ->once()
                ->with(self::TOKEN, '0x313ce567')
                ->andReturn(
                    '0x0000000000000000000000000000000000000000000000000000000000000006'
                );

            $mock->shouldReceive('call')
                ->once()
                ->with(self::TOKEN, '0x95d89b41')
                ->andThrow(new RuntimeException('RPC failed'));
        });

        $metadata = app(EthereumTokenMetadataService::class)->resolve(self::TOKEN);

        $this->assertSame(6, $metadata['decimals']);
        $this->assertNull($metadata['symbol']);
    }

    private function createScan(?string $symbol): TokenScan
    {
        return TokenScan::query()->create([
            'chain' => Chain::Ethereum,
            'address' => self::TOKEN,
            'symbol' => $symbol,
            'first_seen_at' => now(),
            'last_scanned_at' => now(),
        ]);
    }
}
