<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\PaperPosition;
use App\Models\PaperStrategySetting;
use App\Models\PaperWallet;
use App\Models\User;
use App\Services\PaperStrategyService;
use App\Services\PaperTradeEntryService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class PaperStrategySettingsTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
    }

    public function test_application_and_persisted_defaults_preserve_existing_strategy(): void
    {
        $strategy = app(PaperStrategyService::class)->forNewPosition();

        $this->assertSame(10.0, $strategy['stop_loss_percent']);
        $this->assertSame(100.0, $strategy['protection_level_1_percent']);
        $this->assertSame(200.0, $strategy['protection_level_2_percent']);
        $this->assertEqualsWithDelta(0.9, $strategy['stop_loss_multiple'], 0.000001);
        $this->assertEqualsWithDelta(2.0, $strategy['protection_level_1_multiple'], 0.000001);
        $this->assertEqualsWithDelta(3.0, $strategy['protection_level_2_multiple'], 0.000001);
    }

    public function test_user_can_update_personal_strategy(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user);
        $this->post(route('dashboard.paper-strategy.update'), $this->values(15, 75, 180))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success');

        $setting = PaperStrategySetting::query()->where('user_id', $user->id)->where('name', 'default')->firstOrFail();
        $this->assertEqualsWithDelta(15, $setting->stop_loss_percent, 0.000001);
        $this->assertEqualsWithDelta(75, $setting->protection_level_1_percent, 0.000001);
        $this->assertEqualsWithDelta(180, $setting->protection_level_2_percent, 0.000001);
    }

    #[DataProvider('invalidStrategies')]
    public function test_invalid_global_strategy_is_rejected(array $values, string $field): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->from(route('dashboard'))->post(route('dashboard.paper-strategy.update'), $values)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors($field);

        $this->assertEqualsWithDelta(10, PaperStrategySetting::query()->firstOrFail()->stop_loss_percent, 0.000001);
    }

    /** @return array<string, array{array<string, int>, string}> */
    public static function invalidStrategies(): array
    {
        return [
            'zero stop' => [['stop_loss_percent' => 0, 'protection_level_1_percent' => 100, 'protection_level_2_percent' => 200], 'stop_loss_percent'],
            'full stop' => [['stop_loss_percent' => 100, 'protection_level_1_percent' => 100, 'protection_level_2_percent' => 200], 'stop_loss_percent'],
            'zero level one' => [['stop_loss_percent' => 10, 'protection_level_1_percent' => 0, 'protection_level_2_percent' => 200], 'protection_level_1_percent'],
            'unordered levels' => [['stop_loss_percent' => 10, 'protection_level_1_percent' => 200, 'protection_level_2_percent' => 200], 'protection_level_2_percent'],
        ];
    }

    public function test_positions_snapshot_the_effective_strategy_without_retroactive_changes(): void
    {
        $this->createWallet(Chain::Solana);
        $strategies = app(PaperStrategyService::class);
        $entries = app(PaperTradeEntryService::class);

        $strategies->updateGlobal($this->values(12, 80, 160));
        $first = $entries->buy($this->entryData(Chain::Solana, 'first'));
        $strategies->updateGlobal($this->values(20, 120, 240));
        $second = $entries->buy($this->entryData(Chain::Solana, 'second'));

        $this->assertEquals(12.0, $first->fresh()->strategy_snapshot['stop_loss_percent']);
        $this->assertEquals(80.0, $first->fresh()->strategy_snapshot['protection_level_1_percent']);
        $this->assertEquals(160.0, $first->fresh()->strategy_snapshot['protection_level_2_percent']);
        $this->assertEquals(20.0, $second->strategy_snapshot['stop_loss_percent']);
        $this->assertEquals(120.0, $second->strategy_snapshot['protection_level_1_percent']);
        $this->assertEquals(240.0, $second->strategy_snapshot['protection_level_2_percent']);
    }

    public function test_legacy_position_without_snapshot_uses_old_defaults_not_current_global(): void
    {
        app(PaperStrategyService::class)->updateGlobal($this->values(20, 150, 300));
        $resolved = app(PaperStrategyService::class)->forPosition(new PaperPosition(['strategy_snapshot' => null]));

        $this->assertSame('legacy_default', $resolved['source']);
        $this->assertSame(10.0, $resolved['stop_loss_percent']);
        $this->assertSame(100.0, $resolved['protection_level_1_percent']);
        $this->assertSame(200.0, $resolved['protection_level_2_percent']);
    }

    public function test_solana_and_ethereum_entries_use_the_same_global_strategy(): void
    {
        $this->createWallet(Chain::Solana);
        $this->createWallet(Chain::Ethereum);
        app(PaperStrategyService::class)->updateGlobal($this->values(8, 90, 175));
        $entries = app(PaperTradeEntryService::class);

        $solana = $entries->buy($this->entryData(Chain::Solana, 'sol-address'));
        $ethereum = $entries->buy($this->entryData(Chain::Ethereum, '0xethaddress'));

        $this->assertSame($solana->strategy_snapshot, $ethereum->strategy_snapshot);
        $this->assertEquals(8.0, $ethereum->strategy_snapshot['stop_loss_percent']);
    }

    public function test_platform_settings_displays_strategy_editor_and_dashboard_displays_position_snapshot(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->createWallet(Chain::Solana);
        $position = app(PaperTradeEntryService::class)->buy(array_merge(
            $this->entryData(Chain::Solana, 'dashboard-token'),
            ['strategy_override' => $this->values(25, 60, 140)],
        ));

        $this->get(route('dashboard'))
            ->assertSuccessful()
            ->assertDontSee('Save Strategy')
            ->assertSee('Position strategy snapshot')
            ->assertSee('SL -25.00%')
            ->assertSee('P1 +60.00%')
            ->assertSee('P2 +140.00%');

        $this->get(route('settings.index'))
            ->assertSuccessful()
            ->assertSee('Paper position protection')
            ->assertSee('Stop Loss %')
            ->assertSee('Protection 1 %')
            ->assertSee('Protection 2 %');

        $this->assertSame('position_override', $position->strategy_snapshot['source']);
    }

    /** @return array<string, float> */
    private function values(float $stop, float $levelOne, float $levelTwo): array
    {
        return ['stop_loss_percent' => $stop, 'protection_level_1_percent' => $levelOne, 'protection_level_2_percent' => $levelTwo];
    }

    private function createWallet(Chain $chain): PaperWallet
    {
        return PaperWallet::query()->create([
            'name' => 'default', 'chain' => $chain->value,
            'currency' => $chain === Chain::Solana ? 'SOL' : 'ETH',
            'starting_balance_sol' => 5, 'available_balance_sol' => 5,
            'invested_balance_sol' => 0, 'realized_pnl_sol' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    private function entryData(Chain $chain, string $address): array
    {
        return [
            'chain' => $chain->value, 'address' => $address, 'symbol' => 'TEST',
            'name' => 'Test Token', 'entry_market_cap' => 100_000, 'send_notification' => false,
        ];
    }
}
