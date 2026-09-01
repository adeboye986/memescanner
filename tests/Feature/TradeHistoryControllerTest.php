<?php

namespace Tests\Feature;

use App\Models\PaperPosition;
use App\Services\PaperTradeHistoryService;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class TradeHistoryControllerTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshPaperTradingDatabase();
        $this->withoutVite();
    }

    public function test_history_displays_funded_closed_positions_and_excludes_unfunded_records(): void
    {
        $this->createPosition([
            'symbol' => 'FUNDED',
            'status' => 'closed',
            'trade_pnl_sol' => 0.1,
            'closed_at' => now(),
            'exit_events' => [['type' => 'manual_close', 'observed_market_cap' => 200_000]],
        ]);
        $this->createPosition(['symbol' => 'SCANNER_ONLY', 'initial_investment_sol' => 0]);

        $response = $this->get(route('trades.index'));

        $response
            ->assertOk()
            ->assertSee('Trade History & Performance', false)
            ->assertSee('FUNDED')
            ->assertSee('Manual Close')
            ->assertSee('+100.00%')
            ->assertSee('$200,000.00')
            ->assertDontSee('SCANNER_ONLY');
    }

    public function test_open_status_filter_only_displays_open_funded_trades(): void
    {
        $this->createPosition(['symbol' => 'OPEN_TRADE']);
        $this->createPosition(['symbol' => 'CLOSED_TRADE', 'status' => 'closed', 'closed_at' => now()]);

        $response = $this->get(route('trades.index', ['status' => 'open']));

        $response
            ->assertOk()
            ->assertSee('OPEN_TRADE')
            ->assertSee('View on Dashboard')
            ->assertViewHas('trades', fn ($trades): bool => $trades->count() === 1
                && $trades->first()['model']->symbol === 'OPEN_TRADE');
    }

    public function test_result_filters_distinguish_wins_losses_and_break_even(): void
    {
        $this->createPosition(['symbol' => 'WINNER', 'status' => 'closed', 'trade_pnl_sol' => 0.1]);
        $this->createPosition(['symbol' => 'LOSER', 'status' => 'closed', 'trade_pnl_sol' => -0.05]);
        $this->createPosition(['symbol' => 'EVEN', 'status' => 'closed', 'trade_pnl_sol' => 0]);

        $this->get(route('trades.index', ['result' => 'wins']))
            ->assertViewHas('trades', fn ($trades): bool => $trades->count() === 1
                && $trades->first()['model']->symbol === 'WINNER');
        $this->get(route('trades.index', ['result' => 'losses']))
            ->assertViewHas('trades', fn ($trades): bool => $trades->count() === 1
                && $trades->first()['model']->symbol === 'LOSER');
        $this->get(route('trades.index', ['result' => 'break-even']))
            ->assertViewHas('trades', fn ($trades): bool => $trades->count() === 1
                && $trades->first()['model']->symbol === 'EVEN');
    }

    public function test_exit_reason_maps_current_and_unknown_legacy_events(): void
    {
        $cases = [
            'MANUAL' => ['manual_close', 'Manual Close'],
            'STOP' => ['stop_loss', 'Stop Loss (-10%)'],
            'TARGET' => ['full_target_2x_profit', 'Full Target (+200% Profit)'],
            'FLOOR' => ['protected_floor_exit', 'Protected Floor (+100% Profit)'],
            'LEGACY' => ['something_old', 'Other / Unknown'],
        ];

        foreach ($cases as $symbol => [$type]) {
            $this->createPosition([
                'symbol' => $symbol,
                'status' => 'closed',
                'closed_at' => now(),
                'exit_events' => [['type' => $type]],
            ]);
        }

        $response = $this->get(route('trades.index'));

        foreach ($cases as [, $label]) {
            $response->assertSee($label);
        }
    }

    public function test_exit_type_filter_uses_the_final_stored_exit_event(): void
    {
        $this->createPosition([
            'symbol' => 'MANUAL_EXIT',
            'status' => 'closed',
            'exit_events' => [['type' => 'manual_close']],
        ]);
        $this->createPosition([
            'symbol' => 'STOP_EXIT',
            'status' => 'closed',
            'exit_events' => [['type' => 'stop_loss']],
        ]);

        $response = $this->get(route('trades.index', ['exit_type' => 'stop-loss']));

        $response->assertViewHas('trades', fn ($trades): bool => $trades->count() === 1
            && $trades->first()['model']->symbol === 'STOP_EXIT');
    }

    public function test_chain_filter_and_badge_use_the_stored_position_chain(): void
    {
        $this->createPosition(['symbol' => 'SOL_ONLY']);
        $this->createPosition(['chain' => 'ethereum', 'symbol' => 'ETH_ONLY']);

        $response = $this->get(route('trades.index', ['chain' => 'ethereum']));

        $response
            ->assertOk()
            ->assertSee('ETH_ONLY')
            ->assertSee('Ethereum')
            ->assertDontSee('SOL_ONLY')
            ->assertViewHas('trades', fn ($trades): bool => $trades->count() === 1);
    }

    public function test_performance_calculates_win_rate_and_best_and_worst_trade(): void
    {
        $this->createPosition(['symbol' => 'BEST', 'status' => 'closed', 'trade_pnl_sol' => 0.2]);
        $this->createPosition(['symbol' => 'WIN', 'status' => 'closed', 'trade_pnl_sol' => 0.05]);
        $this->createPosition(['symbol' => 'WORST', 'status' => 'closed', 'trade_pnl_sol' => -0.1]);
        $this->createPosition(['symbol' => 'EVEN', 'status' => 'closed', 'trade_pnl_sol' => 0]);

        $summary = app(PaperTradeHistoryService::class)->performanceSummary();

        $this->assertSame(4, $summary['total_trades']);
        $this->assertSame(2, $summary['wins']);
        $this->assertSame(1, $summary['losses']);
        $this->assertSame(1, $summary['break_even']);
        $this->assertEqualsWithDelta(66.67, $summary['win_rate'], 0.01);
        $this->assertSame('BEST', $summary['best_trade']['symbol']);
        $this->assertSame('WORST', $summary['worst_trade']['symbol']);
    }

    public function test_win_rate_is_na_when_no_decided_trades_exist(): void
    {
        $this->createPosition(['status' => 'closed', 'trade_pnl_sol' => 0]);

        $response = $this->get(route('trades.index'));

        $response->assertOk()->assertSee('N/A');
        $this->assertNull(app(PaperTradeHistoryService::class)->performanceSummary()['win_rate']);
    }

    public function test_pagination_preserves_applied_filters(): void
    {
        for ($index = 1; $index <= 25; $index++) {
            $this->createPosition([
                'symbol' => "WIN{$index}",
                'status' => 'closed',
                'trade_pnl_sol' => 0.01,
                'exit_events' => [['type' => 'manual_close']],
            ]);
        }

        $response = $this->get(route('trades.index', [
            'status' => 'closed',
            'result' => 'wins',
            'exit_type' => 'manual',
        ]));

        $response->assertOk()->assertViewHas('trades', function ($trades): bool {
            $nextPageUrl = $trades->nextPageUrl();

            return $trades->total() === 25
                && str_contains($nextPageUrl, 'status=closed')
                && str_contains($nextPageUrl, 'result=wins')
                && str_contains($nextPageUrl, 'exit_type=manual');
        });
    }

    /** @param array<string, mixed> $attributes */
    private function createPosition(array $attributes = []): PaperPosition
    {
        return PaperPosition::query()->create(array_merge([
            'address' => 'token-'.fake()->uuid(),
            'symbol' => 'TOKEN',
            'entry_market_cap' => 100_000,
            'last_market_cap' => 100_000,
            'peak_market_cap' => 150_000,
            'peak_multiple' => 1.5,
            'status' => 'open',
            'entry_at' => now()->subHour(),
            'initial_investment_sol' => 0.1,
            'remaining_investment_sol' => 0.1,
            'remaining_fraction' => 1,
            'trade_pnl_sol' => 0,
            'exit_events' => [],
        ], $attributes));
    }
}
