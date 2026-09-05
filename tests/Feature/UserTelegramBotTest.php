<?php

namespace Tests\Feature;

use App\Models\TelegramIdentity;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use App\Services\TelegramLinkService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class UserTelegramBotTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
        config(['app.url' => 'https://scanner.example.test']);
        app(ApplicationSettingsService::class)->update([
            'telegram.enabled' => true,
            'telegram.bot_token' => 'platform-bot-secret-token',
            'telegram.bot_username' => 'ScannerPlatformBot',
            'telegram.webhook_secret' => 'platform-webhook-secret',
        ]);
    }

    public function test_two_users_receive_distinct_links_to_the_same_platform_bot(): void
    {
        $userA = User::factory()->create(['is_admin' => false]);
        $userB = User::factory()->create(['is_admin' => false]);

        $urlA = app(TelegramLinkService::class)->create($userA);
        $urlB = app(TelegramLinkService::class)->create($userB);

        $this->assertStringContainsString('t.me/ScannerPlatformBot?start=link_', $urlA);
        $this->assertStringContainsString('t.me/ScannerPlatformBot?start=link_', $urlB);
        $this->assertNotSame($urlA, $urlB);
        $this->assertDatabaseCount('telegram_link_tokens', 2);
        $this->assertDatabaseCount('user_telegram_bots', 0);
    }

    public function test_link_tokens_are_stored_hashed_and_not_as_plaintext(): void
    {
        $url = app(TelegramLinkService::class)->create(User::factory()->create(['is_admin' => false]));
        preg_match('/link_([A-Za-z0-9]+)$/', $url, $matches);
        $plainToken = $matches[1];
        $stored = (string) DB::table('telegram_link_tokens')->value('token_hash');

        $this->assertNotSame($plainToken, $stored);
        $this->assertSame(hash('sha256', $plainToken), $stored);
    }

    public function test_same_telegram_human_cannot_be_claimed_by_two_platform_users(): void
    {
        $userA = User::factory()->create(['is_admin' => false]);
        $userB = User::factory()->create(['is_admin' => false]);
        $linkA = app(TelegramLinkService::class)->create($userA);
        $linkB = app(TelegramLinkService::class)->create($userB);
        preg_match('/link_([A-Za-z0-9]+)$/', $linkA, $tokenA);
        preg_match('/link_([A-Za-z0-9]+)$/', $linkB, $tokenB);

        app(TelegramLinkService::class)->consume($tokenA[1], ['id' => 789], '789');

        $this->expectException(DomainException::class);
        app(TelegramLinkService::class)->consume($tokenB[1], ['id' => 789], '789');
    }

    public function test_shared_identity_is_user_scoped_and_not_bound_to_personal_bot(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $url = app(TelegramLinkService::class)->create($user);
        preg_match('/link_([A-Za-z0-9]+)$/', $url, $token);

        $identity = app(TelegramLinkService::class)->consume($token[1], [
            'id' => 456,
            'username' => 'customer',
            'first_name' => 'Test',
        ], '456');

        $this->assertSame($user->id, $identity->user_id);
        $this->assertSame('456', $identity->telegram_user_id);
        $this->assertSame('456', $identity->telegram_chat_id);
        $this->assertNull($identity->user_telegram_bot_id);
    }

    public function test_legacy_byob_identity_does_not_authorize_through_shared_platform_bot(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $legacyBotId = DB::table('user_telegram_bots')->insertGetId([
            'user_id' => $user->id,
            'public_id' => 'A2345678901234567890123456789012',
            'bot_token' => encrypt('legacy-token'),
            'webhook_secret' => encrypt('legacy-secret'),
            'telegram_bot_id' => '123456',
            'bot_username' => 'LegacyBot',
            'display_name' => 'Legacy Bot',
            'enabled' => true,
            'configured_at' => now(),
            'last_verified_at' => now(),
            'webhook_configured_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        TelegramIdentity::factory()->create([
            'user_id' => $user->id,
            'user_telegram_bot_id' => $legacyBotId,
            'telegram_user_id' => '999',
            'status' => 'active',
        ]);

        $this->assertNull(app(TelegramLinkService::class)->authorized('999', null));
    }

    public function test_normal_user_cannot_edit_or_see_global_platform_credentials(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('settings.index'))->assertForbidden();
        $response = $this->actingAs($user)->get(route('telegram.settings'))->assertOk();
        $response->assertDontSee('platform-bot-secret-token');
        $response->assertDontSee('platform-webhook-secret');
    }
}
