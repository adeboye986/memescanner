<?php

namespace Tests\Feature;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Models\User;
use App\Services\PaperWalletService;
use App\Services\UserTradingBootstrapService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
        $this->withoutVite();
    }

    public function test_guest_can_view_registration(): void
    {
        $this->get(route('register'))->assertOk()->assertSee('Create your account');
    }

    public function test_guest_can_register_with_safe_isolated_defaults(): void
    {
        Notification::fake();

        $response = $this->post(route('register.store'), [
            'name' => 'New Customer', 'email' => 'CUSTOMER@EXAMPLE.COM', 'password' => 'password123', 'password_confirmation' => 'password123',
            'is_admin' => true, 'user_id' => 999,
        ]);

        $user = User::query()->where('email', 'customer@example.com')->firstOrFail();
        $response->assertRedirect(route('onboarding'));
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_admin);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertSame(ExecutionMode::Paper, $user->tradingPreference->execution_mode);
        $this->assertSame(EntryMode::Signal, $user->tradingPreference->entry_mode);
        $this->assertDatabaseHas('paper_wallets', ['user_id' => $user->id, 'chain' => 'solana']);
        $this->assertDatabaseHas('paper_wallets', ['user_id' => $user->id, 'chain' => 'ethereum']);
        $this->assertDatabaseHas('paper_strategy_settings', ['user_id' => $user->id, 'name' => 'default']);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post(route('register.store'), ['name' => 'Duplicate', 'email' => 'taken@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_legacy_mixed_case_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'Legacy@Example.COM']);

        $this->post(route('register.store'), ['name' => 'Duplicate', 'email' => 'legacy@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_bootstrap_failure_rolls_back_account_creation(): void
    {
        $bootstrap = $this->mock(UserTradingBootstrapService::class);
        $bootstrap->shouldReceive('bootstrap')->once()->andThrow(new RuntimeException('Bootstrap failed.'));

        $this->post(route('register.store'), ['name' => 'Incomplete', 'email' => 'incomplete@example.com', 'password' => 'password123', 'password_confirmation' => 'password123']);

        $this->assertDatabaseMissing('users', ['email' => 'incomplete@example.com']);
        $this->assertDatabaseCount('paper_wallets', 0);
    }

    public function test_bootstrap_is_idempotent_and_preserves_existing_state(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $bootstrap = app(UserTradingBootstrapService::class);
        $bootstrap->bootstrap($user);
        $wallet = app(PaperWalletService::class)->forUser($user, Chain::Solana);
        $wallet->update(['available_balance_sol' => 3.25]);
        $user->tradingPreference->update(['entry_mode' => EntryMode::Auto]);

        $bootstrap->bootstrap($user);

        $this->assertDatabaseCount('paper_wallets', 2);
        $this->assertSame(3.25, $wallet->fresh()->available_balance_sol);
        $this->assertSame(EntryMode::Auto, $user->tradingPreference->fresh()->entry_mode);
    }
}
