<?php

namespace Tests\Feature;

use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Models\TradingEnginePaperWalletProjection;
use App\Models\User;
use App\Models\UserTradingPreference;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class TradingDashboardTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshPaperTradingDatabase();
        $this->withoutVite();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('d', 32))]);
    }

    public function test_trading_screen_requires_authentication_and_defaults_to_engine_paper_positions(): void
    {
        $this->get(route('trades.index'))->assertRedirect(route('login'));

        $user = User::factory()->create(['is_admin' => false]);
        $this->createLegacyPosition($user, ['symbol' => 'LEGACY_ONLY']);

        $this->actingAs($user)
            ->get(route('trades.index'))
            ->assertOk()
            ->assertSee('data-trading-screen', false)
            ->assertSee('PAPER · SIMULATED')
            ->assertSee('Engine PAPER')
            ->assertSee('No open Engine PAPER positions')
            ->assertDontSee('LEGACY_ONLY')
            ->assertViewHas('filters', fn (array $filters): bool => $filters['tab'] === 'positions'
                && $filters['source'] === 'engine');
    }

    public function test_legacy_positions_are_user_scoped_and_manual_close_action_is_preserved(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $otherUser = User::factory()->create(['is_admin' => false]);
        $position = $this->createLegacyPosition($user, [
            'symbol' => 'OWN_POSITION',
            'entry_price' => 1,
            'last_price' => 1.2,
            'last_market_cap' => 120_000,
            'last_checked_at' => now(),
        ]);
        $this->createLegacyPosition($otherUser, [
            'symbol' => 'OTHER_POSITION',
            'last_checked_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('trades.index', ['tab' => 'positions', 'source' => 'legacy']))
            ->assertOk()
            ->assertSee('OWN_POSITION')
            ->assertDontSee('OTHER_POSITION')
            ->assertSee('+0.0200 SOL')
            ->assertSee('Current observation')
            ->assertSee(route('paper-trades.close', $position), false)
            ->assertSee('open-close-modal', false)
            ->assertSee('Fresh provider data will be fetched and validated when you confirm.');
    }

    public function test_stale_and_missing_market_observations_never_become_zero_or_calculated_pnl(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->createLegacyPosition($user, [
            'symbol' => 'STALE_POSITION',
            'entry_price' => 1,
            'last_price' => 2,
            'last_market_cap' => 200_000,
            'last_checked_at' => now()->subMinutes(5),
        ]);
        $this->createLegacyPosition($user, [
            'symbol' => 'MISSING_POSITION',
            'last_price' => null,
            'last_checked_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('trades.index', ['tab' => 'positions', 'source' => 'legacy']))
            ->assertOk()
            ->assertSee('STALE_POSITION')
            ->assertSee('Stale observation')
            ->assertSee('MISSING_POSITION')
            ->assertSee('Not reported')
            ->assertSee('Needs fresh data')
            ->assertDontSee('+0.1000 SOL');
    }

    public function test_summary_does_not_add_unlike_native_currencies(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->createWallet($user, 'solana', 'SOL', 4.5, 0.5);
        $this->createWallet($user, 'ethereum', 'ETH', 4.75, 0.25);

        $this->actingAs($user)
            ->get(route('trades.index', ['tab' => 'performance', 'source' => 'legacy']))
            ->assertOk()
            ->assertSee('Separate native-currency ledgers')
            ->assertSee('Never combined across currencies')
            ->assertSee('4.5000 SOL')
            ->assertSee('4.7500 ETH')
            ->assertDontSee('10.0000');
    }

    public function test_engine_and_legacy_wallet_values_remain_source_separated(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->createWallet($user, 'solana', 'SOL', 9, 0);
        TradingEnginePaperWalletProjection::query()->create([
            'engine_wallet_id' => '01J00000000000000000000001',
            'user_id' => $user->id,
            'network_id' => 'solana:mainnet',
            'currency' => 'SOL',
            'opening_balance_native' => '1',
            'available_balance_native' => '0.75',
            'invested_balance_native' => '0.25',
            'realized_pnl_native' => '0',
            'last_event_id' => '01J00000000000000000000002',
            'last_event_occurred_at' => now(),
            'last_payload_sha256' => str_repeat('a', 64),
            'projected_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('trades.index', ['source' => 'engine']))
            ->assertOk()
            ->assertSee('1.0000 SOL')
            ->assertDontSee('9.0000 SOL');

        $this->get(route('trades.index', ['source' => 'legacy']))
            ->assertOk()
            ->assertSee('9.0000 SOL')
            ->assertDontSee('1.0000 SOL');
    }

    public function test_live_preference_is_disclosed_without_turning_trading_screen_into_a_live_action_surface(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        UserTradingPreference::factory()->create([
            'user_id' => $user->id,
            'execution_mode' => ExecutionMode::Live,
            'entry_mode' => EntryMode::Confirm,
        ]);

        $this->actingAs($user)
            ->get(route('trades.index'))
            ->assertOk()
            ->assertSee('PAPER · SIMULATED')
            ->assertSee('this screen remains PAPER-only')
            ->assertSee('LIVE actions stay behind the existing opportunity confirmation and wallet safeguards')
            ->assertDontSee('Execute LIVE');
    }

    public function test_trading_screen_contains_dark_light_and_responsive_table_contracts(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->createLegacyPosition($user, ['last_checked_at' => now()]);

        $this->actingAs($user)
            ->get(route('trades.index', ['tab' => 'positions', 'source' => 'legacy']))
            ->assertOk()
            ->assertSee('data-theme-toggle', false)
            ->assertSee('data-theme-icon="light"', false)
            ->assertSee('data-theme-icon="dark"', false)
            ->assertSee('data-trading-position-table', false)
            ->assertSee('hidden overflow-x-auto md:block', false)
            ->assertSee('data-trading-position-mobile-list', false)
            ->assertSee('md:hidden', false)
            ->assertSee('grid grid-cols-2', false);
    }

    public function test_invalid_tab_source_and_search_are_rejected(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('trades.index', [
                'tab' => 'orders',
                'source' => 'combined',
                'search' => str_repeat('x', 101),
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['tab', 'source', 'search']);
    }

    /** @param array<string, mixed> $attributes */
    private function createLegacyPosition(User $user, array $attributes = []): PaperPosition
    {
        return PaperPosition::query()->create(array_merge([
            'user_id' => $user->id,
            'address' => 'token-'.fake()->uuid(),
            'symbol' => 'TOKEN',
            'chain' => 'solana',
            'entry_market_cap' => 100_000,
            'last_market_cap' => 100_000,
            'peak_market_cap' => 100_000,
            'peak_multiple' => 1,
            'status' => 'open',
            'entry_at' => now()->subHour(),
            'initial_investment_sol' => 0.1,
            'remaining_investment_sol' => 0.1,
            'remaining_fraction' => 1,
            'trade_pnl_sol' => 0,
            'exit_events' => [],
        ], $attributes));
    }

    private function createWallet(
        User $user,
        string $chain,
        string $currency,
        float $available,
        float $invested,
    ): PaperWallet {
        return PaperWallet::query()->create([
            'user_id' => $user->id,
            'name' => 'default',
            'chain' => $chain,
            'currency' => $currency,
            'starting_balance_sol' => $available + $invested,
            'available_balance_sol' => $available,
            'invested_balance_sol' => $invested,
            'realized_pnl_sol' => 0,
        ]);
    }
}
