<?php

namespace Tests\Feature;

use App\Exceptions\EthereumAccountingException;
use App\Models\User;
use App\Services\EthereumEligibilityObservationCollector;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EthereumEligibilityObservationCollectorTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_snapshot_is_mainnet_canonical_historical_and_rechecked(): void
    {
        $this->reviewer();
        $this->fake();
        $collector = app(EthereumEligibilityObservationCollector::class);
        $captured = $collector->capture(self::TOKEN);
        $this->assertSame(1, $captured['observation']['chain_id']);
        $this->assertSame('100', $captured['observation']['block_number']);
        $this->assertSame(hash('sha256', hex2bin('6000')), $captured['observation']['code_sha256']);
        $this->assertSame($captured['observation'], $collector->collect(self::TOKEN, $captured['reference']));
        Http::assertSent(fn ($request) => $request['method'] === 'eth_getCode' && $request['params'] === [self::TOKEN, ['blockHash' => '0x'.str_repeat('b', 64), 'requireCanonical' => true]]);
        Http::assertSent(fn ($request) => $request['method'] === 'eth_getBlockByNumber' && $request['params'] === ['0x64', false]);
        Http::assertNotSent(fn ($request) => ! in_array($request['method'], ['eth_chainId', 'eth_getBlockByNumber', 'eth_getCode'], true));
    }

    #[DataProvider('unusableProviders')]
    public function test_unusable_provider_cannot_produce_snapshot(string $case): void
    {
        $this->reviewer();
        $this->fake($case);
        $this->expectException($case === 'noncanonical' ? DomainException::class : EthereumAccountingException::class);
        app(EthereumEligibilityObservationCollector::class)->capture(self::TOKEN);
    }

    public static function unusableProviders(): array
    {
        return [['wrong chain'], ['missing block'], ['empty code'], ['noncanonical'], ['error'], ['timeout'], ['malformed']];
    }

    #[DataProvider('invalidReferences')]
    public function test_snapshot_reference_is_bound_to_actor_token_and_expiry(string $case): void
    {
        $this->reviewer();
        $this->fake();
        $collector = app(EthereumEligibilityObservationCollector::class);
        $captured = $collector->capture(self::TOKEN);
        $token = self::TOKEN;
        $reference = $captured['reference'];
        if ($case === 'actor') {
            $this->reviewer();
        } elseif ($case === 'token') {
            $token = '0x'.str_repeat('c', 40);
        } elseif ($case === 'expired') {
            $this->travel(21)->minutes();
        } else {
            $reference = (string) Str::uuid();
        }
        $this->expectException(DomainException::class);
        $collector->collect($token, $reference);
    }

    public static function invalidReferences(): array
    {
        return [['actor'], ['token'], ['expired'], ['unknown']];
    }

    private function reviewer(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        config(['services.ethereum.accounting.reviewer_ids' => [$user->id], 'services.ethereum.metadata_cache_store' => 'array', 'services.ethereum.rpc_url' => 'https://collector.test']);
    }

    private function fake(string $case = ''): void
    {
        Http::fake(['https://collector.test' => function ($request) use ($case) {
            if ($case === 'timeout') {
                throw new ConnectionException('Timed out');
            }
            if ($case === 'error') {
                return Http::response([], 503);
            }
            $result = match ($request['method']) {
                'eth_chainId' => $case === 'wrong chain' ? '0x2' : '0x1',
                'eth_getCode' => $case === 'empty code' ? '0x' : '0x6000',
                'eth_getBlockByNumber' => $case === 'missing block' ? null : ['number' => '0x64', 'timestamp' => '0x1',
                    'hash' => '0x'.str_repeat($case === 'noncanonical' && $request['params'][0] === '0x64' ? 'c' : 'b', 64)],
                default => throw new \RuntimeException('Unexpected RPC'),
            };

            return Http::response($case === 'malformed' ? ['result' => $result] : ['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        }]);
    }
}
