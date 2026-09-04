<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserTelegramBot;
use App\Services\TelegramLinkService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        Http::fake(function ($request) {
            $id = str_contains($request->url(), 'token-for-bot-b') ? 222 : 111;

            return Http::response(['ok' => true, 'result' => str_ends_with($request->url(), '/getMe')
                ? ['id' => $id, 'is_bot' => true, 'first_name' => 'Scanner', 'username' => $id === 222 ? 'BotB' : 'BotA']
                : true]);
        });
    }

    public function test_two_users_can_connect_distinct_encrypted_bots(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->actingAs($userA)->put(route('telegram.connect'), ['bot_token' => 'token-for-bot-a', 'bot_username' => '@BotA'])->assertRedirect(route('telegram.settings'));
        $this->actingAs($userB)->put(route('telegram.connect'), ['bot_token' => 'token-for-bot-b', 'bot_username' => 'BotB'])->assertRedirect(route('telegram.settings'));

        $botA = $userA->telegramBot()->firstOrFail();
        $botB = $userB->telegramBot()->firstOrFail();
        $this->assertNotSame($botA->public_id, $botB->public_id);
        $this->assertSame('BotA', $botA->bot_username);
        $this->assertSame('BotB', $botB->bot_username);
        $rawTokens = DB::table('user_telegram_bots')->pluck('bot_token')->implode(' ');
        $rawSecrets = DB::table('user_telegram_bots')->pluck('webhook_secret')->implode(' ');
        $this->assertStringNotContainsString('token-for-bot-a', $rawTokens);
        $this->assertStringNotContainsString('token-for-bot-b', $rawTokens);
        $this->assertStringNotContainsString((string) $botA->webhook_secret, $rawSecrets);
        $this->assertStringNotContainsString((string) $botB->webhook_secret, $rawSecrets);
    }

    public function test_blank_token_update_preserves_encrypted_credentials_and_never_renders_token(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->put(route('telegram.connect'), ['bot_token' => 'token-for-bot-a', 'bot_username' => 'BotA']);
        $encryptedBefore = DB::table('user_telegram_bots')->value('bot_token');

        $this->actingAs($user)->put(route('telegram.connect'), ['bot_token' => '', 'bot_username' => 'BotA'])->assertSessionHas('success');

        $this->assertSame($encryptedBefore, DB::table('user_telegram_bots')->value('bot_token'));
        $this->actingAs($user)->get(route('telegram.settings'))->assertOk()->assertDontSee('token-for-bot-a');
    }

    public function test_failed_token_replacement_preserves_existing_bot_and_does_not_flash_token(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->put(route('telegram.connect'), ['bot_token' => 'token-for-bot-a', 'bot_username' => 'BotA']);
        $bot = $user->telegramBot()->firstOrFail();
        $response = $this->actingAs($user)->put(route('telegram.connect'), ['bot_token' => 'replacement-secret-token', 'bot_username' => 'WrongBot']);

        $response->assertSessionHas('error')->assertSessionMissing('_old_input.bot_token');
        $this->assertSame('token-for-bot-a', $bot->fresh()->bot_token);
        $this->assertStringNotContainsString('replacement-secret-token', (string) $response->getContent());
    }

    public function test_link_urls_use_own_bot_and_cannot_cross_bot_context(): void
    {
        $botA = UserTelegramBot::factory()->create(['bot_username' => 'BotA']);
        $botB = UserTelegramBot::factory()->create(['bot_username' => 'BotB']);
        $urlA = app(TelegramLinkService::class)->create($botA->user);
        $urlB = app(TelegramLinkService::class)->create($botB->user);
        preg_match('/link_([A-Za-z0-9]+)$/', $urlA, $matches);

        $this->assertStringContainsString('t.me/BotA', $urlA);
        $this->assertStringContainsString('t.me/BotB', $urlB);
        $this->expectException(DomainException::class);
        app(TelegramLinkService::class)->consume($matches[1], ['id' => 456], '456', $botB);
    }

    public function test_same_telegram_human_cannot_be_claimed_by_two_users(): void
    {
        $botA = UserTelegramBot::factory()->create();
        $botB = UserTelegramBot::factory()->create();
        $linkA = app(TelegramLinkService::class)->create($botA->user);
        $linkB = app(TelegramLinkService::class)->create($botB->user);
        preg_match('/link_([A-Za-z0-9]+)$/', $linkA, $tokenA);
        preg_match('/link_([A-Za-z0-9]+)$/', $linkB, $tokenB);
        app(TelegramLinkService::class)->consume($tokenA[1], ['id' => 789], '789', $botA);

        $this->expectException(DomainException::class);
        app(TelegramLinkService::class)->consume($tokenB[1], ['id' => 789], '789', $botB);
    }

    public function test_normal_user_cannot_edit_global_platform_credentials(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($user)->get(route('telegram.settings'))->assertOk();
        $this->assertDatabaseCount('telegram_link_tokens', 0);
    }
}
