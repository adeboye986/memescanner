<?php

namespace Tests\Feature;

use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class ClosePaperPositionCommandTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    private const TOKEN = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const POOL = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const QUOTE = '0xcccccccccccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshPaperTradingDatabase();
        config(['services.trading.paper_tracker_cache_store' => 'array']);
        Cache::store('array')->flush();
    }

    public function test_successful_guarded_close_displays_validated_observation_provenance(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([$this->ethereumPair()])]);
        $this->mock(TelegramService::class)->shouldReceive('send')->once();
        $wallet = $this->createWallet();
        $position = $this->createPosition();

        $exitCode = Artisan::call('tokens:paper-close', [
            'position' => (string) $position->id,
            '--force' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('PAPER TRADE CLOSED: ETHMEME', $output);
        $this->assertStringContainsString('Fresh validated provider observation', $output);
        $this->assertStringContainsString('dexscreener', $output);
        $this->assertStringContainsString(now()->toIso8601String(), $output);
        $this->assertStringContainsString('Observed Liquidity USD', $output);
        $this->assertStringContainsString('$50,000.00', $output);
        $this->assertStringContainsString('provider_market_cap', $output);
        $this->assertStringContainsString(
            'PAPER simulation only: this provider mark does not verify execution, slippage, or market depth.',
            $output,
        );

        $event = $position->fresh()->exit_events[0];
        $this->assertSame('closed', $position->fresh()->status);
        $this->assertFalse($event['execution_verified']);
        $this->assertSame('observed_mark_without_slippage_or_depth', $event['fill_model']);
        $this->assertEqualsWithDelta(5.1, $wallet->fresh()->available_balance_sol, 0.000001);
        $this->assertSame(0.0, $wallet->fresh()->invested_balance_sol);
        $this->assertEqualsWithDelta(0.1, $wallet->fresh()->realized_pnl_sol, 0.000001);
        Http::assertSentCount(1);
    }

    public function test_unavailable_current_data_returns_failure_and_preserves_position_and_wallet(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.dexscreener.com/*' => Http::response([], 503),
            'api.geckoterminal.com/*' => Http::response([], 503),
        ]);
        $wallet = $this->createWallet();
        $position = $this->createPosition([
            'last_market_cap' => 75_000,
            'last_price' => 0.00075,
        ]);

        $exitCode = Artisan::call('tokens:paper-close', [
            'position' => (string) $position->id,
            '--force' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            'Guarded PAPER close rejected because the current provider observation is not eligible for simulation:',
            $output,
        );

        $this->assertSame('open', $position->fresh()->status);
        $this->assertSame([], $position->fresh()->exit_events ?? []);
        $this->assertNull($position->fresh()->closed_at);
        $this->assertSame(4.9, $wallet->fresh()->available_balance_sol);
        $this->assertSame(0.1, $wallet->fresh()->invested_balance_sol);
        $this->assertSame(0.0, $wallet->fresh()->realized_pnl_sol);
        Http::assertSentCount(3);
    }

    private function createWallet(): PaperWallet
    {
        return PaperWallet::query()->create([
            'name' => 'default',
            'chain' => 'ethereum',
            'currency' => 'ETH',
            'starting_balance_sol' => 5,
            'available_balance_sol' => 4.9,
            'invested_balance_sol' => 0.1,
            'realized_pnl_sol' => 0,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createPosition(array $attributes = []): PaperPosition
    {
        return PaperPosition::query()->create(array_replace_recursive([
            'chain' => 'ethereum',
            'address' => self::TOKEN,
            'symbol' => 'ETHMEME',
            'entry_market_cap' => 100_000,
            'entry_price' => 0.001,
            'last_market_cap' => 100_000,
            'last_price' => 0.001,
            'peak_market_cap' => 100_000,
            'status' => 'open',
            'entry_at' => now(),
            'initial_investment_sol' => 0.1,
            'remaining_investment_sol' => 0.1,
            'remaining_fraction' => 1,
            'meta' => ['pair_address' => self::POOL],
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function ethereumPair(): array
    {
        return [
            'chainId' => 'ethereum',
            'dexId' => 'uniswap',
            'pairAddress' => self::POOL,
            'baseToken' => ['address' => self::TOKEN, 'symbol' => 'ETHMEME'],
            'quoteToken' => ['address' => self::QUOTE, 'symbol' => 'WETH'],
            'priceUsd' => '0.002',
            'marketCap' => 200_000,
            'liquidity' => ['usd' => 50_000],
        ];
    }
}
