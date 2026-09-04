<?php

namespace Tests\Feature;

use App\Enums\EntryMode;
use App\Jobs\RunDashboardCommand;
use App\Models\TelegramIdentity;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Models\UserTelegramBot;
use App\Services\OnboardingStatusService;
use App\Services\UserTradingBootstrapService;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
        $this->withoutVite();
    }

    public function test_onboarding_status_is_derived_from_real_state(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        app(UserTradingBootstrapService::class)->bootstrap($user);

        $initial = app(OnboardingStatusService::class)->forUser($user);

        $this->assertTrue($initial['account']);
        $this->assertTrue($initial['email']);
        $this->assertTrue($initial['paper_account']);
        $this->assertFalse($initial['telegram_bot']);
        $this->assertFalse($initial['ready']);

        $bot = UserTelegramBot::factory()->for($user)->create();
        TelegramIdentity::factory()->for($user)->create(['user_telegram_bot_id' => $bot->id, 'status' => 'active']);
        $complete = app(OnboardingStatusService::class)->forUser($user->fresh());

        $this->assertTrue($complete['telegram_bot']);
        $this->assertTrue($complete['telegram_link']);
        $this->assertTrue($complete['ready']);

        $bot->update(['enabled' => false]);
        $this->assertFalse(app(OnboardingStatusService::class)->forUser($user->fresh())['ready']);
        $bot->update(['enabled' => true]);
        $bot->identity()->delete();
        $this->assertFalse(app(OnboardingStatusService::class)->forUser($user->fresh())['ready']);
    }

    public function test_incomplete_customer_sees_onboarding_and_dashboard_prompt_but_admin_is_not_forced(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($customer)->get(route('onboarding'))->assertOk()->assertSee('Set up your trading workspace');
        $this->get(route('dashboard'))->assertOk()->assertSee('Complete your setup');
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertDontSee('Complete your setup');
    }

    public function test_unverified_user_cannot_connect_bot_scan_approve_or_enable_auto(): void
    {
        $user = User::factory()->unverified()->create(['is_admin' => false]);
        $opportunity = TradeOpportunity::factory()->create(['user_id' => $user->id, 'status' => 'pending_confirmation']);

        $this->actingAs($user)->put(route('telegram.connect'), ['bot_token' => 'secret', 'bot_username' => 'ExampleBot'])->assertRedirect(route('verification.notice'));
        $this->post(route('dashboard.actions.store', 'token-scan'), ['chain' => 'solana'])->assertRedirect(route('verification.notice'));
        $this->post(route('opportunities.approve', $opportunity))->assertRedirect(route('verification.notice'));
        $this->put(route('dashboard.trading-preferences.update'), ['execution_mode' => 'paper', 'entry_mode' => 'auto'])->assertForbidden();
    }

    public function test_verified_user_can_change_modes_and_first_scan_is_user_scoped(): void
    {
        Queue::fake([RunDashboardCommand::class]);
        $user = User::factory()->create(['is_admin' => false]);
        app(UserTradingBootstrapService::class)->bootstrap($user);

        $this->actingAs($user)->put(route('dashboard.trading-preferences.update'), ['execution_mode' => 'paper', 'entry_mode' => 'confirm'])->assertSessionHas('success');
        $this->put(route('dashboard.trading-preferences.update'), ['execution_mode' => 'paper', 'entry_mode' => 'auto'])->assertSessionHas('success');
        $this->put(route('dashboard.trading-preferences.update'), ['execution_mode' => 'live', 'entry_mode' => 'auto'])->assertSessionHasErrors('execution_mode');
        $this->post(route('dashboard.actions.store', 'token-scan'), ['chain' => 'solana'])->assertSessionHas('success');

        $this->assertSame(EntryMode::Auto, $user->tradingPreference->fresh()->entry_mode);
        $this->assertDatabaseHas('system_activities', ['user_id' => $user->id, 'action' => 'token-scan', 'chain' => 'solana']);
        Queue::assertPushed(RunDashboardCommand::class);
    }

    public function test_registered_customers_receive_separate_wallets_and_no_admin_access(): void
    {
        foreach ([['Alice', 'alice@example.com'], ['Bob', 'bob@example.com']] as [$name, $email]) {
            $this->post(route('register.store'), ['name' => $name, 'email' => $email, 'password' => 'password123', 'password_confirmation' => 'password123']);
            $this->post(route('logout'));
        }
        $alice = User::query()->where('email', 'alice@example.com')->firstOrFail();
        $bob = User::query()->where('email', 'bob@example.com')->firstOrFail();

        $this->assertDatabaseCount('paper_wallets', 4);
        $this->assertNotSame($alice->paperWallets()->where('chain', 'solana')->value('id'), $bob->paperWallets()->where('chain', 'solana')->value('id'));
        $this->actingAs($alice)->get(route('settings.index'))->assertForbidden();
    }

    public function test_legacy_unverified_admin_keeps_operational_access(): void
    {
        Queue::fake([RunDashboardCommand::class]);
        $admin = User::factory()->unverified()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('dashboard.actions.store', 'token-scan'), ['chain' => 'solana'])->assertSessionHas('success');

        $this->assertDatabaseHas('system_activities', ['user_id' => $admin->id, 'action' => 'token-scan']);
    }
}
