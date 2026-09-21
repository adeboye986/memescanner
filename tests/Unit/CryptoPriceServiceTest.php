<?php

namespace Tests\Unit;

use App\Services\CryptoPriceService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CryptoPriceServiceTest extends TestCase
{
    public function test_valid_sol_usd_price_and_lamport_value_are_normalized(): void
    {
        $this->freezeTime();
        config()->set('services.coingecko.base_url', 'https://price.test');
        Http::preventStrayRequests();
        Http::fake(['https://price.test/simple/price*' => Http::response([
            'solana' => ['usd' => 197.2, 'last_updated_at' => now()->subSeconds(30)->timestamp],
        ])]);

        $service = app(CryptoPriceService::class);

        $this->assertSame('197.2', $service->solUsdPrice());
        $this->assertSame('1.34', $service->usdForLamports(6_793_573, '197.2'));
        Http::assertSent(fn ($request): bool => $request['include_last_updated_at'] === 'true');
    }

    public function test_malformed_price_response_is_rejected(): void
    {
        $this->freezeTime();
        config()->set('services.coingecko.base_url', 'https://price.test');
        Http::fake(['https://price.test/simple/price*' => Http::response([
            'solana' => ['usd' => 'not-a-price', 'last_updated_at' => now()->timestamp],
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider returned invalid data');

        app(CryptoPriceService::class)->solUsdPrice();
    }

    public function test_missing_last_updated_timestamp_is_rejected(): void
    {
        config()->set('services.coingecko.base_url', 'https://price.test');
        Http::fake(['https://price.test/simple/price*' => Http::response(['solana' => ['usd' => 197.2]])]);

        $this->expectException(RuntimeException::class);

        app(CryptoPriceService::class)->solUsdPrice();
    }

    public function test_invalid_last_updated_timestamp_is_rejected(): void
    {
        config()->set('services.coingecko.base_url', 'https://price.test');
        Http::fake(['https://price.test/simple/price*' => Http::response([
            'solana' => ['usd' => 197.2, 'last_updated_at' => 'not-a-timestamp'],
        ])]);

        $this->expectException(RuntimeException::class);

        app(CryptoPriceService::class)->solUsdPrice();
    }

    public function test_stale_price_is_rejected_using_configured_maximum_age(): void
    {
        $this->freezeTime();
        config()->set('services.coingecko.base_url', 'https://price.test');
        config()->set('services.coingecko.max_price_age_seconds', 120);
        Http::fake(['https://price.test/simple/price*' => Http::response([
            'solana' => ['usd' => 197.2, 'last_updated_at' => now()->subSeconds(121)->timestamp],
        ])]);

        $this->expectException(RuntimeException::class);

        app(CryptoPriceService::class)->solUsdPrice();
    }

    public function test_timestamp_beyond_clock_skew_tolerance_is_rejected(): void
    {
        $this->freezeTime();
        config()->set('services.coingecko.base_url', 'https://price.test');
        Http::fake(['https://price.test/simple/price*' => Http::response([
            'solana' => ['usd' => 197.2, 'last_updated_at' => now()->addSeconds(31)->timestamp],
        ])]);

        $this->expectException(RuntimeException::class);

        app(CryptoPriceService::class)->solUsdPrice();
    }

    public function test_zero_and_negative_prices_are_rejected(): void
    {
        $service = app(CryptoPriceService::class);

        foreach (['0', '-1'] as $price) {
            try {
                $service->usdForLamports(1_000_000_000, $price);
                $this->fail("Price {$price} was accepted.");
            } catch (RuntimeException $exception) {
                $this->assertSame('SOL market price provider returned invalid data.', $exception->getMessage());
            }
        }
    }

    public function test_provider_error_is_handled_without_exposing_response(): void
    {
        config()->set('services.coingecko.base_url', 'https://price.test');
        Http::fake(['https://price.test/simple/price*' => Http::response(['secret' => 'provider details'], 500)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SOL market price is temporarily unavailable.');

        app(CryptoPriceService::class)->solUsdPrice();
    }

    public function test_connection_failure_is_handled(): void
    {
        config()->set('services.coingecko.base_url', 'https://price.test');
        Http::fake(['https://price.test/simple/price*' => Http::failedConnection('timeout')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SOL market price is temporarily unavailable.');

        app(CryptoPriceService::class)->solUsdPrice();
    }
}
