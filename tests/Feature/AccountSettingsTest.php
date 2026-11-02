<?php

namespace Tests\Feature;

use App\Models\TelegramIdentity;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
        $this->withoutVite();
    }

    public function test_user_can_update_name_and_email_change_requires_reverification(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)->put(route('account.update'), ['name' => 'Updated Name', 'email' => 'NEW@EXAMPLE.COM'])->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_password_change_requires_current_password_and_updates_hash(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $this->actingAs($user)->put(route('account.password.update'), ['current_password' => 'wrong', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertSessionHasErrors('current_password');

        $this->put(route('account.password.update'), ['current_password' => 'old-password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertSessionHas('success');

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_email_update_rejects_case_insensitive_legacy_duplicate(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        User::factory()->create(['email' => 'Legacy@Example.COM']);

        $this->actingAs($user)->put(route('account.update'), ['name' => 'Owner', 'email' => 'legacy@example.com'])->assertSessionHasErrors('email');

        $this->assertSame('owner@example.com', $user->fresh()->email);
    }

    public function test_settings_screen_organizes_only_supported_user_preferences(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $trading = $this->actingAs($user)->get(route('account.edit'))->assertOk();

        $trading->assertSee('data-settings-screen', false)
            ->assertSee('data-settings-section="trading"', false)
            ->assertSee('Settings')
            ->assertSee('Manage your account, trading preferences, and notifications.')
            ->assertSee('data-theme-toggle', false)
            ->assertSee('data-theme-icon="light"', false)
            ->assertSee('data-theme-icon="dark"', false)
            ->assertSee('overflow-x-auto', false)
            ->assertSee('Trading Preferences')
            ->assertSee('Risk Management')
            ->assertSee('Notifications')
            ->assertSee('Security')
            ->assertSee('name="execution_mode"', false)
            ->assertSee('action="'.route('dashboard.trading-preferences.update').'"', false)
            ->assertSee('LIVE AUTO and LIVE SIGNAL are unavailable.')
            ->assertDontSee('Diagnostics')
            ->assertDontSee('Two-factor authentication is enabled.');

        $this->get(route('account.edit', ['section' => 'general']))->assertOk()
            ->assertSee('action="'.route('account.update').'"', false)
            ->assertSee(route('wallets.index'), false);
        $this->get(route('account.edit', ['section' => 'risk']))->assertOk()
            ->assertSee('action="'.route('dashboard.paper-strategy.update').'"', false)
            ->assertSee('Existing positions retain their stored strategy snapshot.');
        $this->get(route('account.edit', ['section' => 'security']))->assertOk()
            ->assertSee('action="'.route('account.password.update').'"', false)
            ->assertSee('not currently supported');
    }

    public function test_settings_notifications_are_user_scoped_and_do_not_expose_platform_secrets(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $otherUser = User::factory()->create(['is_admin' => false]);
        TelegramIdentity::factory()->create(['user_id' => $user->id, 'telegram_username' => 'owned_identity']);
        TelegramIdentity::factory()->create(['user_id' => $otherUser->id, 'telegram_username' => 'other_identity']);

        $response = $this->actingAs($user)->get(route('account.edit', ['section' => 'notifications']))->assertOk();

        $response->assertSee('@owned_identity')
            ->assertDontSee('other_identity')
            ->assertSee(route('telegram.settings'), false)
            ->assertDontSee('telegram.bot_token')
            ->assertDontSee('telegram.webhook_secret');
    }

    public function test_diagnostics_section_is_available_only_to_administrators(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);
        $administrator = User::factory()->create(['is_admin' => true]);

        $this->actingAs($customer)->get(route('account.edit', ['section' => 'diagnostics']))->assertOk()
            ->assertSee('data-settings-section="trading"', false)
            ->assertDontSee('Administrator diagnostics');

        $this->actingAs($administrator)->get(route('account.edit', ['section' => 'diagnostics']))->assertOk()
            ->assertSee('data-settings-section="diagnostics"', false)
            ->assertSee('Administrator diagnostics')
            ->assertSee(route('settings.index'), false)
            ->assertSee(route('dashboard', ['view' => 'tools']).'#system-status', false);
    }
}
