<?php

namespace Tests\Unit;

use App\Chain;
use App\Models\TokenScan;
use App\Services\SolanaTokenMetadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SolanaTokenMetadataServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.solana.metadata_cache_store', 'array');
    }

    public function test_valid_token_scan_symbol_and_decimals_are_used_without_rpc(): void
    {
        $this->createScan('USDC', ['decimals' => 6]);
        Http::preventStrayRequests();

        $metadata = app(SolanaTokenMetadataService::class)->resolve($this->mint());

        $this->assertSame([
            'mint' => $this->mint(),
            'symbol' => 'USDC',
            'decimals' => 6,
        ], $metadata);
        Http::assertNothingSent();
    }

    public function test_missing_scan_decimals_fall_back_to_cached_rpc_token_supply(): void
    {
        $this->createScan('USDC', []);
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response(['jsonrpc' => '2.0', 'result' => ['value' => ['decimals' => 6]], 'id' => 1]),
        ]);
        $service = app(SolanaTokenMetadataService::class);

        $first = $service->resolve($this->mint());
        $second = $service->resolve($this->mint());

        $this->assertSame(6, $first['decimals']);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['method'] === 'getTokenSupply'
            && $request['params'][0] === $this->mint());
    }

    public function test_symbol_remains_null_when_existing_scan_has_no_safe_symbol(): void
    {
        $this->createScan('   ', ['decimals' => 6]);
        Http::preventStrayRequests();

        $metadata = app(SolanaTokenMetadataService::class)->resolve($this->mint());

        $this->assertNull($metadata['symbol']);
        Http::assertNothingSent();
    }

    public function test_malformed_rpc_decimals_are_rejected_safely(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response(['jsonrpc' => '2.0', 'result' => ['value' => ['decimals' => '6']], 'id' => 1]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Solana RPC returned invalid token metadata.');

        app(SolanaTokenMetadataService::class)->resolve($this->mint());
    }

    public function test_rpc_connection_failure_is_normalized_safely(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::failedConnection('timeout'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Solana token metadata is temporarily unavailable.');

        app(SolanaTokenMetadataService::class)->resolve($this->mint());
    }

    public function test_invalid_mint_is_rejected_without_rpc(): void
    {
        Http::preventStrayRequests();

        try {
            app(SolanaTokenMetadataService::class)->resolve('not-a-mint');
            $this->fail('Invalid mint was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The Solana token mint is invalid.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $rawData */
    private function createScan(?string $symbol, array $rawData): TokenScan
    {
        return TokenScan::query()->create([
            'chain' => Chain::Solana,
            'address' => $this->mint(),
            'symbol' => $symbol,
            'raw_data' => $rawData,
            'first_seen_at' => now(),
            'last_scanned_at' => now(),
        ]);
    }

    private function mint(): string
    {
        return 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';
    }
}
