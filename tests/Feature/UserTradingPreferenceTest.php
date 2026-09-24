<?php

namespace Tests\Feature;

use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Models\UserTradingPreference;
use App\Services\ApplicationSettingsService;
use App\Services\UserTradingBootstrapService;
use App\Services\UserTradingPreferenceService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserTradingPreferenceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('bootstrapModes')]
    public function test_bootstrap_normalizes_only_new_preferences_without_executing(bool $admin, string $execution, string $entry, string $expectedExecution, string $expectedEntry): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $settings = app(ApplicationSettingsService::class);
        $settings->update(['trading.execution_mode' => $execution, 'trading.entry_mode' => $entry]);
        $user = User::factory()->create(['is_admin' => $admin]);

        app(UserTradingBootstrapService::class)->bootstrap($user);

        $this->assertDatabaseHas('user_trading_preferences', ['user_id' => $user->id,
            'execution_mode' => $expectedExecution, 'entry_mode' => $expectedEntry]);
        $this->assertSame($execution, $settings->get('trading.execution_mode'));
        $this->assertSame($entry, $settings->get('trading.entry_mode'));
        foreach (['paper_positions', 'ethereum_swap_attempts', 'live_positions', 'trade_opportunities', 'trade_opportunity_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public static function bootstrapModes(): array
    {
        return [
            'admin live confirm' => [true, 'live', 'confirm', 'live', 'confirm'],
            'admin live signal' => [true, 'live', 'signal', 'live', 'confirm'],
            'admin live auto' => [true, 'live', 'auto', 'live', 'confirm'],
            'admin paper signal' => [true, 'paper', 'signal', 'paper', 'signal'],
            'admin paper confirm' => [true, 'paper', 'confirm', 'paper', 'confirm'],
            'admin paper auto' => [true, 'paper', 'auto', 'paper', 'auto'],
            'customer live confirm' => [false, 'live', 'confirm', 'paper', 'signal'],
            'customer live signal' => [false, 'live', 'signal', 'paper', 'signal'],
            'customer live auto' => [false, 'live', 'auto', 'paper', 'signal'],
        ];
    }

    #[DataProvider('persistedModes')]
    public function test_bootstrap_preserves_existing_preferences_even_if_they_are_unsupported(string $execution, string $entry): void
    {
        app(ApplicationSettingsService::class)->update(['trading.execution_mode' => 'live', 'trading.entry_mode' => 'auto']);
        $user = User::factory()->create(['is_admin' => true]);
        $preference = UserTradingPreference::factory()->for($user)->create(['execution_mode' => $execution, 'entry_mode' => $entry]);
        $before = $preference->fresh()->getRawOriginal();

        app(UserTradingBootstrapService::class)->bootstrap($user);

        $this->assertSame($before, $preference->fresh()->getRawOriginal());
        $this->assertDatabaseCount('user_trading_preferences', 1);
    }

    public static function persistedModes(): array
    {
        return [['paper', 'auto'], ['live', 'confirm'], ['live', 'signal'], ['live', 'auto']];
    }

    #[DataProvider('allowedModes')]
    public function test_web_saves_supported_modes_without_executing_or_changing_history(string $execution, string $entry): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $user = User::factory()->create(['is_admin' => false]);
        $opportunity = TradeOpportunity::factory()->create(['user_id' => $user->id, 'execution_mode' => 'paper', 'entry_mode' => 'confirm']);
        app(UserTradingBootstrapService::class)->bootstrap($user);
        $this->assertDatabaseCount('paper_wallets', 2);
        $history = $opportunity->fresh()->getRawOriginal();
        $wallets = DB::table('paper_wallets')->get()->toArray();

        $this->actingAs($user)->put(route('dashboard.trading-preferences.update'), ['execution_mode' => $execution, 'entry_mode' => $entry])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Your trading preferences were updated.');

        $this->assertDatabaseHas('user_trading_preferences', ['user_id' => $user->id, 'execution_mode' => $execution, 'entry_mode' => $entry]);
        $this->assertSame($history, $opportunity->fresh()->getRawOriginal());
        $this->assertEquals($wallets, DB::table('paper_wallets')->get()->toArray());
        foreach (['paper_positions', 'ethereum_swap_attempts', 'live_positions', 'trade_opportunity_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public static function allowedModes(): array
    {
        return ['paper signal' => ['paper', 'signal'], 'paper confirm' => ['paper', 'confirm'],
            'paper auto' => ['paper', 'auto'], 'live confirm' => ['live', 'confirm']];
    }

    #[DataProvider('unsupportedLiveModes')]
    public function test_web_rejects_unsupported_live_modes_without_changing_preference(string $entry): void
    {
        $user = User::factory()->create();
        $preference = app(UserTradingPreferenceService::class)->forUser($user);
        $before = $preference->fresh()->getRawOriginal();

        $this->actingAs($user)->put(route('dashboard.trading-preferences.update'), ['execution_mode' => 'live', 'entry_mode' => $entry])
            ->assertSessionHasErrors('execution_mode');

        $this->assertSame($before, $preference->fresh()->getRawOriginal());
    }

    #[DataProvider('unsupportedLiveModes')]
    public function test_direct_service_rejects_unsupported_live_modes_before_creating_preferences(string $entry): void
    {
        $user = User::factory()->create();
        try {
            app(UserTradingPreferenceService::class)->update($user, ExecutionMode::Live, EntryMode::from($entry));
            $this->fail('Unsupported LIVE combination was accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('LIVE supports CONFIRM only. Select CONFIRM before switching to LIVE.', $exception->getMessage());
        }
        $this->assertDatabaseCount('user_trading_preferences', 0);
    }

    public static function unsupportedLiveModes(): array
    {
        return ['signal' => ['signal'], 'auto' => ['auto']];
    }

    public function test_unverified_user_cannot_enable_paper_auto(): void
    {
        $user = User::factory()->unverified()->create(['is_admin' => false]);

        $this->actingAs($user)->put(route('dashboard.trading-preferences.update'), ['execution_mode' => 'paper', 'entry_mode' => 'auto'])->assertForbidden();

        $this->assertDatabaseCount('user_trading_preferences', 0);
    }

    public function test_guest_cannot_change_preferences(): void
    {
        $this->put(route('dashboard.trading-preferences.update'), ['execution_mode' => 'live', 'entry_mode' => 'confirm'])->assertRedirect(route('login'));
        $this->assertDatabaseCount('user_trading_preferences', 0);
    }

    public function test_concurrent_preference_change_cannot_merge_into_live_signal(): void
    {
        $user = User::factory()->create();
        $service = app(UserTradingPreferenceService::class);
        $preference = $service->update($user, ExecutionMode::Paper, EntryMode::Confirm);
        $interleaved = false;
        DB::listen(function ($query) use (&$interleaved, $preference): void {
            if (! $interleaved && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'user_trading_preferences')) {
                $interleaved = true;
                DB::table('user_trading_preferences')->where('id', $preference->id)->update(['execution_mode' => 'live', 'entry_mode' => 'confirm']);
            }
        });

        $result = $service->update($user, ExecutionMode::Paper, EntryMode::Signal);

        $this->assertTrue($interleaved);
        $this->assertSame(ExecutionMode::Paper, $result->execution_mode);
        $this->assertSame(EntryMode::Signal, $result->entry_mode);
    }

    public function test_dashboard_and_onboarding_show_actual_live_preference_and_explicit_selector(): void
    {
        $this->withoutVite();
        $user = User::factory()->create(['is_admin' => false]);
        app(UserTradingPreferenceService::class)->update($user, ExecutionMode::Live, EntryMode::Confirm);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('name="execution_mode"', false)->assertSee('value="live" selected', false)
            ->assertSee('LIVE supports CONFIRM only')->assertDontSee('Live execution remains unavailable.')
            ->assertDontSee('<input type="hidden" name="execution_mode"', false);
        $this->get(route('onboarding'))->assertOk()->assertSee('Current: LIVE + CONFIRM');
    }
}
