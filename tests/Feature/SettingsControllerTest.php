<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Models\SettingAudit;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
    }

    public function test_settings_require_authentication_and_admin_authorization(): void
    {
        $this->get(route('settings.index'))->assertRedirect(route('login'));
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get(route('settings.index'))->assertForbidden();
    }

    public function test_admin_sees_masked_secrets_but_never_decrypted_values_in_html(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        app(ApplicationSettingsService::class)->update(['telegram.bot_token' => 'super-secret-9FA2'], $admin);

        $this->actingAs($admin)->get(route('settings.index'))
            ->assertSuccessful()
            ->assertSee('••••••••••••••••9FA2')
            ->assertDontSee('super-secret-9FA2');
    }

    public function test_admin_updates_settings_and_audit_never_contains_secret(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->put(route('settings.update'), $this->payload([
            'execution_mode' => 'live', 'entry_mode' => 'auto', 'telegram_bot_token' => 'new-secret-token',
        ]))->assertRedirect(route('settings.index'))->assertSessionHas('warning');

        $this->assertDatabaseHas('application_settings', ['key' => 'trading.execution_mode', 'value' => 'live']);
        $this->assertStringNotContainsString('new-secret-token', ApplicationSetting::query()->where('key', 'telegram.bot_token')->sole()->value);
        $this->assertFalse(SettingAudit::query()->get()->contains(fn (SettingAudit $audit): bool => str_contains((string) $audit->old_value.$audit->new_value, 'new-secret-token')));
        $this->assertDatabaseHas('setting_audits', ['setting_key' => 'strategy.paper']);
    }

    public function test_live_auto_settings_show_both_locked_execution_warnings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        app(ApplicationSettingsService::class)->update([
            'trading.execution_mode' => 'live',
            'trading.entry_mode' => 'auto',
        ], $admin);

        $this->actingAs($admin)->get(route('settings.index'))
            ->assertSuccessful()
            ->assertSee('LIVE EXECUTION LOCKED')
            ->assertSee('Automatic real-money trading will require explicit activation');
    }

    public function test_blank_secret_form_value_keeps_existing_secret(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $settings = app(ApplicationSettingsService::class);
        $settings->update(['telegram.bot_token' => 'keep-this-token'], $admin);

        $this->actingAs($admin)->put(route('settings.update'), $this->payload(['telegram_bot_token' => '']))->assertSessionHas('success');

        $this->assertSame('keep-this-token', $settings->getSecret('telegram.bot_token'));
    }

    public function test_telegram_connection_test_sends_harmless_message_without_exposing_token(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $settings = app(ApplicationSettingsService::class);
        $settings->update(['telegram.bot_token' => '123:secret', 'telegram.chat_id' => '-1001'], $admin);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->actingAs($admin)->post(route('settings.test', 'telegram'))
            ->assertSessionHas('success', 'Telegram test message sent successfully.')
            ->assertSessionMissing('123:secret');
        Http::assertSent(fn ($request): bool => $request['text'] === 'Meme Scanner Telegram integration is working.');
    }

    public function test_provider_connection_tests_are_read_only_and_report_real_health(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        app(ApplicationSettingsService::class)->update([
            'blockchain.solana_rpc_url' => 'https://rpc.example.test',
        ], $admin);
        Http::fake([
            'https://rpc.example.test' => Http::response(['jsonrpc' => '2.0', 'result' => 'ok', 'id' => 1]),
            'https://api.geckoterminal.com/*' => Http::response(['data' => []]),
            'https://api.dexscreener.com/*' => Http::response(['pairs' => []]),
        ]);

        $this->actingAs($admin)->post(route('settings.test', 'solana'))
            ->assertSessionHas('success', 'Solana RPC connection is healthy.');
        $this->post(route('settings.test', 'ethereum'))
            ->assertSessionHas('success', 'Ethereum market-discovery connection is healthy.');
        $this->post(route('settings.test', 'market-data'))
            ->assertSessionHas('success', 'DexScreener market-data connection is healthy.');

        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://rpc.example.test'
            && $request->method() === 'POST'
            && $request['method'] === 'getHealth');
        Http::assertNotSent(fn ($request): bool => in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true));
    }

    public function test_failed_connection_test_returns_a_safe_message_without_provider_details(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Http::fake(['*' => Http::response(['error' => 'credential secret leaked by provider'], 401)]);

        $this->actingAs($admin)->post(route('settings.test', 'market-data'))
            ->assertSessionHas('error', 'Connection test failed. Check the integration configuration and application logs.')
            ->assertSessionMissing('credential secret leaked by provider');
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'application_name' => 'Meme Scanner', 'execution_mode' => 'paper', 'entry_mode' => 'auto',
            'max_chase_percent' => 35, 'telegram_enabled' => 1, 'telegram_bot_token' => '', 'telegram_chat_id' => '',
            'birdeye_api_key' => '', 'solana_rpc_url' => '', 'tracker_snapshot_seconds' => 10, 'kill_switch' => 0,
            'max_trade_amount' => 0.1, 'max_open_positions' => 10, 'max_daily_loss' => 1,
            'max_slippage_percent' => 1, 'minimum_wallet_reserve' => 0.1, 'trade_cooldown_seconds' => 60,
            'stop_loss_percent' => 10, 'protection_level_1_percent' => 100, 'protection_level_2_percent' => 200,
        ], $overrides);
    }
}
