<?php

namespace Tests\Unit;

use App\Services\GoPlusEthereumService;
use App\Services\GoPlusService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoPlusEthereumServiceTest extends TestCase
{
    private const TOKEN = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';

    private const SAFE = ['is_open_source' => '1', 'is_mintable' => '0', 'owner_change_balance' => '0',
        'transfer_pausable' => '0', 'is_honeypot' => '0', 'cannot_buy' => '0', 'cannot_sell_all' => '0'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_safe_token_uses_ethereum_chain_and_normalized_address_and_authentication(): void
    {
        config(['services.goplus.access_token' => 'test-token']);
        Http::fake(['api.gopluslabs.io/*' => Http::response(['code' => 1, 'result' => [self::TOKEN => self::SAFE]])]);
        $result = app(GoPlusEthereumService::class)->evaluateToken('0x'.strtoupper(substr(self::TOKEN, 2)));
        $this->assertTrue($result['passed']);
        $this->assertSame(self::SAFE, $result['checks']);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.gopluslabs.io/api/v1/token_security/1?')
            && $request['contract_addresses'] === self::TOKEN && $request->hasHeader('Authorization', 'Bearer test-token'));
        Http::assertSentCount(1);
    }

    #[DataProvider('unsafeFlags')]
    public function test_critical_risks_and_unknown_required_flags_fail_closed(string $field, mixed $value): void
    {
        $data = self::SAFE;
        $data[$field] = $value;
        Http::fake(['api.gopluslabs.io/*' => Http::response(['code' => 1, 'result' => [self::TOKEN => $data]])]);
        $this->assertFalse(app(GoPlusEthereumService::class)->evaluateToken(self::TOKEN)['passed']);
    }

    public static function unsafeFlags(): array
    {
        $cases = [];
        foreach (self::SAFE as $field => $safe) {
            foreach ([$safe === '1' ? '0' : '1', null, '', false, 0, [], 'unknown'] as $index => $value) {
                $cases[$field.'-'.$index] = [$field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('malformedResponses')]
    public function test_malformed_or_missing_token_responses_fail_closed(mixed $body): void
    {
        Http::fake(['api.gopluslabs.io/*' => Http::response($body)]);
        $result = app(GoPlusEthereumService::class)->evaluateToken(self::TOKEN);
        $this->assertFalse($result['passed']);
        $this->assertFalse($result['available']);
        $this->assertSame('malformed', $result['failure_class']);
    }

    public static function malformedResponses(): array
    {
        return [[null], ['not json'], [[]], [['code' => 0, 'result' => [self::TOKEN => self::SAFE]]],
            [['code' => 1, 'result' => []]], [['code' => 1, 'result' => ['wrong-token' => self::SAFE]]],
            [['code' => 1, 'result' => [self::TOKEN => []]]], [['code' => 1, 'result' => [self::TOKEN => 'unsafe']]],
            [['code' => 1, 'result' => [self::TOKEN => self::SAFE, strtoupper(self::TOKEN) => self::SAFE]]]];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_errors_fail_closed_without_retry(int $status): void
    {
        Http::fake(['api.gopluslabs.io/*' => $status === 0 ? Http::failedConnection() : Http::response(['secret' => 'provider detail'], $status)]);
        $result = app(GoPlusEthereumService::class)->evaluateToken(self::TOKEN);
        $this->assertFalse($result['passed']);
        $this->assertSame('unavailable', $result['failure_class']);
        if ($status !== 0) {
            Http::assertSentCount(1);
        }
    }

    public static function providerFailures(): array
    {
        return [[0], [429], [500], [401]];
    }

    public function test_explicit_unsafe_flag_remains_terminal_even_when_another_flag_is_unknown(): void
    {
        Http::fake(['api.gopluslabs.io/*' => Http::response(['code' => 1, 'result' => [self::TOKEN => ['is_honeypot' => '1']]])]);
        $result = app(GoPlusEthereumService::class)->evaluateToken(self::TOKEN);
        $this->assertFalse($result['passed']);
        $this->assertSame('unsafe', $result['failure_class']);
    }

    public function test_solana_address_is_rejected_without_request(): void
    {
        $this->assertFalse(app(GoPlusEthereumService::class)->evaluateToken('So11111111111111111111111111111111111111112')['passed']);
        Http::assertNothingSent();
    }

    public function test_existing_solana_parser_and_endpoint_remain_unchanged(): void
    {
        $address = 'So11111111111111111111111111111111111111112';
        Http::fake(['api.gopluslabs.io/api/v1/solana/token_security*' => Http::response(['result' => [$address => ['mintable' => ['status' => '0']]]])]);
        $this->assertTrue(app(GoPlusService::class)->evaluateToken($address)['passed']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/solana/token_security?') && $request['contract_addresses'] === $address);
        Http::assertSentCount(1);
    }
}
