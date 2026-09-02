<?php

namespace Tests\Feature;

use App\Chain;
use App\Jobs\RunDashboardCommand;
use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Models\SystemActivity;
use App\Services\PaperTradeEntryService;
use App\Services\PaperTradeExitService;
use App\Services\SystemActivityService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class MultiChainTradingTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshPaperTradingDatabase();
    }

    public function test_chain_values_and_invalid_command_options_are_stable(): void
    {
        $this->assertSame(Chain::Solana, Chain::fromInput('solana'));
        $this->assertSame(Chain::Ethereum, Chain::fromInput('ETHEREUM'));
        $this->artisan('tokens:scan', ['--chain' => 'bitcoin'])->assertFailed();
        $this->artisan('tokens:momentum', ['--chain' => 'random'])->assertFailed();
    }

    public function test_only_the_paper_tracker_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('tokens:paper-track')
            ->doesntExpectOutputToContain('tokens:scan')
            ->doesntExpectOutputToContain('tokens:momentum')
            ->assertSuccessful();
    }

    public function test_entry_identity_is_chain_plus_address_and_duplicate_buys_are_prevented(): void
    {
        PaperWallet::query()->create([
            'name' => 'default',
            'starting_balance_sol' => 5,
            'available_balance_sol' => 5,
            'invested_balance_sol' => 0,
            'realized_pnl_sol' => 0,
        ]);
        $this->mock(TelegramService::class)->shouldNotReceive('send');
        $entries = app(PaperTradeEntryService::class);
        $base = [
            'address' => 'same-address',
            'symbol' => 'SAME',
            'entry_market_cap' => 10_000,
            'scanner' => 'new-token',
            'send_notification' => false,
        ];

        $solana = $entries->buy($base);
        $duplicate = $entries->buy($base);
        $ethereum = $entries->buy(array_merge($base, ['chain' => 'ethereum']));
        $momentum = $entries->buy(array_merge($base, [
            'address' => 'momentum-address',
            'scanner' => 'momentum',
        ]));

        $this->assertTrue($solana->wasRecentlyCreated);
        $this->assertTrue($solana->is($duplicate));
        $this->assertTrue($ethereum->wasRecentlyCreated);
        $this->assertSame(Chain::Solana, $solana->chain);
        $this->assertSame(Chain::Ethereum, $ethereum->chain);
        $this->assertSame('new-token', $solana->meta['scanner']);
        $this->assertSame('momentum', $momentum->meta['scanner']);
        $this->assertSame(3, PaperPosition::query()->count());
        $this->assertEqualsWithDelta(4.8, (float) PaperWallet::query()->where('chain', 'solana')->sole()->available_balance_sol, 0.000001);
        $this->assertEqualsWithDelta(4.9, (float) PaperWallet::query()->where('chain', 'ethereum')->sole()->available_balance_sol, 0.000001);
    }

    public function test_dashboard_queues_selected_chain_and_duplicate_protection_is_per_chain(): void
    {
        Queue::fake([RunDashboardCommand::class]);

        $this->post(route('dashboard.actions.store', 'momentum-scan'), ['chain' => 'ethereum'])
            ->assertSessionHas('success', 'Run Momentum Scan — Ethereum was queued.');

        $activity = SystemActivity::query()->sole();
        $this->assertSame(Chain::Ethereum, $activity->chain);
        $this->assertSame(['momentum-scan:ethereum'], app(SystemActivityService::class)->runningActions());

        $this->post(route('dashboard.actions.store', 'momentum-scan'), ['chain' => 'ethereum'])
            ->assertSessionHas('warning');
        $this->post(route('dashboard.actions.store', 'momentum-scan'), ['chain' => 'solana'])
            ->assertSessionHas('success', 'Run Momentum Scan — Solana was queued.');

        $this->assertSame(2, SystemActivity::query()->count());
    }

    public function test_tracker_and_manual_close_route_market_lookups_using_the_position_chain(): void
    {
        PaperWallet::query()->create([
            'name' => 'default',
            'starting_balance_sol' => 5,
            'available_balance_sol' => 4.9,
            'invested_balance_sol' => 0.1,
            'realized_pnl_sol' => 0,
        ]);
        $position = PaperPosition::query()->create([
            'chain' => 'ethereum',
            'address' => '0xabc',
            'symbol' => 'ETHMEME',
            'entry_market_cap' => 10_000,
            'entry_at' => now(),
            'last_market_cap' => 10_000,
            'peak_market_cap' => 10_000,
            'status' => 'open',
            'initial_investment_sol' => 0.1,
            'remaining_investment_sol' => 0.1,
            'remaining_fraction' => 1,
        ]);
        Http::fake([
            'api.dexscreener.com/token-pairs/v1/ethereum/*' => Http::response([[
                'chainId' => 'ethereum',
                'dexId' => 'uniswap',
                'pairAddress' => '0xpair',
                'baseToken' => ['address' => '0xAbC', 'symbol' => 'ETHMEME'],
                'quoteToken' => ['address' => '0xquote', 'symbol' => 'WETH'],
                'priceUsd' => '1',
                'marketCap' => 10_000,
                'liquidity' => ['usd' => 5_000],
                'txns' => ['m5' => ['buys' => 1, 'sells' => 1]],
                'volume' => ['m5' => 1_000],
            ]]),
        ]);
        $this->mock(TelegramService::class)->shouldReceive('send')->zeroOrMoreTimes();

        $this->artisan('tokens:paper-track')->assertSuccessful();
        $this->assertNotNull($position->fresh()->last_checked_at);

        app(PaperTradeExitService::class)->closeManually($position->fresh());

        $this->assertSame('closed', $position->fresh()->status);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/token-pairs/v1/ethereum/0xabc'));
    }
}
