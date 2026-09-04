<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class AuthenticationLifecycleTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
        $this->withoutVite();
    }

    public function test_login_and_logout_work_for_customer_and_admin(): void
    {
        foreach ([false, true] as $isAdmin) {
            $user = User::factory()->create(['email' => ($isAdmin ? 'admin' : 'user').'@example.com', 'password' => 'password123', 'is_admin' => $isAdmin]);
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password123'])->assertRedirect(route('dashboard'));
            $this->assertAuthenticatedAs($user);
            $this->post(route('logout'))->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_password_reset_uses_laravel_broker(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('success');
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $this->post(route('password.update'), ['token' => $notification->token, 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertRedirect(route('login'));

            return true;
        });
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_email_verification_marks_user_verified(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($user)->get($url)->assertRedirect(route('onboarding'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Notification::assertNothingSent();
    }

    public function test_expired_email_verification_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->travel(2)->minutes();

        $this->actingAs($user)->get($url)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_unknown_password_reset_email_gets_non_disclosing_response(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'missing@example.com'])->assertSessionHas('success');

        Notification::assertNothingSent();
    }

    public function test_expired_password_reset_token_cannot_change_password(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'expired@example.com', 'password' => 'old-password']);
        $this->post(route('password.email'), ['email' => $user->email]);
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $this->travel(((int) config('auth.passwords.users.expire')) + 1)->minutes();
            $this->post(route('password.update'), ['token' => $notification->token, 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertSessionHasErrors('email');

            return true;
        });

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }
}
