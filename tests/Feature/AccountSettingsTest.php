<?php

namespace Tests\Feature;

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
}
