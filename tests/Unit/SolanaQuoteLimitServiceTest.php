<?php

namespace Tests\Unit;

use App\Services\ApplicationSettingsService;
use App\Services\SolanaQuoteLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolanaQuoteLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_sol_limits_are_converted_to_lamports_exactly(): void
    {
        $limits = app(SolanaQuoteLimitService::class);

        app(ApplicationSettingsService::class)->update(['risk.max_trade_amount' => '0.1']);
        $this->assertSame(100_000_000, $limits->maximumLamports());

        app(ApplicationSettingsService::class)->update(['risk.max_trade_amount' => '0.01']);
        $this->assertSame(10_000_000, $limits->maximumLamports());

        app(ApplicationSettingsService::class)->update(['risk.max_trade_amount' => '0.000000001']);
        $this->assertSame(1, $limits->maximumLamports());
    }

    public function test_malformed_or_over_precision_limits_fail_closed(): void
    {
        $settings = $this->mock(ApplicationSettingsService::class);
        $limits = new SolanaQuoteLimitService($settings);

        foreach (['not-a-number', '-1', '0.0000000001', '999999999999999999999'] as $value) {
            $settings->shouldReceive('get')->once()->with('risk.max_trade_amount')->andReturn($value);
            $this->assertSame(1, $limits->maximumLamports());
        }
    }

    public function test_suggested_spend_is_small_and_never_exceeds_balance_or_risk_limit(): void
    {
        app(ApplicationSettingsService::class)->update(['risk.max_trade_amount' => '0.0001']);
        $limits = app(SolanaQuoteLimitService::class);

        $suggested = $limits->suggestedSpendLamports(1_000_000);

        $this->assertSame(100_000, $limits->maximumLamports());
        $this->assertSame(10_000, $suggested);
        $this->assertLessThan(1_000_000, $suggested);
        $this->assertLessThanOrEqual($limits->maximumLamports(), $suggested);
    }

    public function test_tiny_balance_without_safe_suggestion_returns_zero(): void
    {
        $this->assertSame(0, app(SolanaQuoteLimitService::class)->suggestedSpendLamports(9));
    }
}
