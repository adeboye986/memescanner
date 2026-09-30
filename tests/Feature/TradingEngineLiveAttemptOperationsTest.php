<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\ConnectedWallet;
use App\Models\EthereumSwapAttempt;
use App\Models\SolanaSwapAttempt;
use App\Models\User;
use App\Services\TradingEngine\TradingEngineLiveAttemptInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class TradingEngineLiveAttemptOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_abandoned_preparation_and_armed_unknown_outcome_are_classified_without_mutation(): void
    {
        $this->travelTo('2026-09-30 12:00:00');
        config()->set('services.trading_engine.live_attempt_attention_after_seconds', 900);
        [$user, $ethereumWallet, $solanaWallet] = $this->actors();
        $safe = $this->ethereumAttempt($user, $ethereumWallet, [
            'status' => 'preparing',
            'preparation_expires_at' => now()->subSecond(),
        ]);
        $uncertain = $this->solanaAttempt($user, $solanaWallet, [
            'status' => 'prepared',
            'signing_requested_at' => now()->subMinutes(20),
            'signing_armed_at' => now()->subMinutes(20),
            'expires_at' => now()->subMinutes(15),
        ]);
        $beforeEthereum = DB::table('ethereum_swap_attempts')->where('id', $safe->id)->first();
        $beforeSolana = DB::table('solana_swap_attempts')->where('id', $uncertain->id)->first();

        $attempts = app(TradingEngineLiveAttemptInspector::class)->inspect();

        $ethereumStatus = $attempts->firstWhere('chain', 'ethereum');
        $solanaStatus = $attempts->firstWhere('chain', 'solana');
        $this->assertSame(TradingEngineLiveAttemptInspector::CLASSIFICATION_SAFE_TO_CLEAN, $ethereumStatus['classification']);
        $this->assertSame('PREPARATION_LEASE_EXPIRED', $ethereumStatus['reason']);
        $this->assertSame(TradingEngineLiveAttemptInspector::CLASSIFICATION_UNCERTAIN_BROADCAST, $solanaStatus['classification']);
        $this->assertSame('SIGNING_ARMED_OUTCOME_UNRESOLVED_PAST_THRESHOLD', $solanaStatus['reason']);
        $this->assertSame('prepared', $uncertain->fresh()->status);
        $this->assertNull($uncertain->transaction_signature);
        $this->assertSame(
            serialize($beforeEthereum),
            serialize(DB::table('ethereum_swap_attempts')->where('id', $safe->id)->first()),
        );
        $this->assertSame(
            serialize($beforeSolana),
            serialize(DB::table('solana_swap_attempts')->where('id', $uncertain->id)->first()),
        );
    }

    public function test_submitted_attempts_are_reconciliation_eligible_and_terminal_attempts_are_ignored(): void
    {
        $this->travelTo('2026-09-30 12:00:00');
        config()->set('services.trading_engine.live_attempt_attention_after_seconds', 900);
        [$user, $ethereumWallet, $solanaWallet] = $this->actors();
        $ethereum = $this->ethereumAttempt($user, $ethereumWallet, [
            'status' => 'submitted',
            'transaction_hash' => '0x'.str_repeat('a', 64),
            'submitted_at' => now()->subMinutes(20),
        ]);
        $solana = $this->solanaAttempt($user, $solanaWallet, [
            'status' => 'submitting',
            'transaction_signature' => 'known-signature',
            'submitted_at' => now()->subMinute(),
        ]);
        $terminal = $this->ethereumAttempt($user, $ethereumWallet, [
            'status' => 'confirmed',
            'transaction_hash' => '0x'.str_repeat('b', 64),
            'submitted_at' => now()->subHour(),
        ]);

        $attempts = app(TradingEngineLiveAttemptInspector::class)->inspect();

        $ethereumStatus = $attempts->first(
            fn (array $attempt): bool => $attempt['chain'] === 'ethereum'
                && $attempt['attempt_id'] === $ethereum->id,
        );
        $solanaStatus = $attempts->first(
            fn (array $attempt): bool => $attempt['chain'] === 'solana'
                && $attempt['attempt_id'] === $solana->id,
        );
        $this->assertSame(TradingEngineLiveAttemptInspector::CLASSIFICATION_ATTENTION, $ethereumStatus['classification']);
        $this->assertSame('RECEIPT_UNRESOLVED_PAST_THRESHOLD', $ethereumStatus['reason']);
        $this->assertTrue($ethereumStatus['reconciliation_eligible']);
        $this->assertSame(TradingEngineLiveAttemptInspector::CLASSIFICATION_RECONCILIATION, $solanaStatus['classification']);
        $this->assertSame('RECEIPT_RECONCILIATION_PENDING', $solanaStatus['reason']);
        $this->assertTrue($solanaStatus['reconciliation_eligible']);
        $this->assertNull($attempts->first(
            fn (array $attempt): bool => $attempt['chain'] === 'ethereum'
                && $attempt['attempt_id'] === $terminal->id,
        ));
    }

    public function test_operator_command_is_bounded_read_only_and_performs_no_external_or_trading_work(): void
    {
        $this->travelTo('2026-09-30 12:00:00');
        [$user, $ethereumWallet, $solanaWallet] = $this->actors();
        $this->ethereumAttempt($user, $ethereumWallet, [
            'status' => 'submitted',
            'transaction_hash' => '0x'.str_repeat('c', 64),
            'submitted_at' => now()->subMinutes(20),
        ]);
        $this->solanaAttempt($user, $solanaWallet, [
            'status' => 'prepared',
            'signing_armed_at' => now()->subMinutes(20),
            'expires_at' => now()->subMinute(),
        ]);
        $beforeEthereum = DB::table('ethereum_swap_attempts')->orderBy('id')->get()->toArray();
        $beforeSolana = DB::table('solana_swap_attempts')->orderBy('id')->get()->toArray();
        Queue::fake();
        Http::preventStrayRequests();

        $this->artisan('trading-engine:live-attempts', ['--limit' => 1])
            ->expectsOutputToContain('Found 2 active or unresolved LIVE attempt(s).')
            ->expectsOutputToContain('RECEIPT_UNRESOLVED_PAST_THRESHOLD')
            ->expectsOutputToContain('SIGNING_ARMED_OUTCOME_UNRESOLVED_PAST_THRESHOLD')
            ->assertSuccessful();

        $this->assertSame(
            serialize($beforeEthereum),
            serialize(DB::table('ethereum_swap_attempts')->orderBy('id')->get()->toArray()),
        );
        $this->assertSame(
            serialize($beforeSolana),
            serialize(DB::table('solana_swap_attempts')->orderBy('id')->get()->toArray()),
        );
        $this->assertDatabaseCount('live_positions', 0);
        $this->assertDatabaseCount('trade_opportunity_events', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_reconciliation_commands_remain_scheduled_each_minute_with_overlap_protection(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach (['ethereum:reconcile-submitted-swaps', 'solana:reconcile-submitted-swaps'] as $command) {
            $event = $events->first(fn ($candidate): bool => str_contains($candidate->command ?? '', $command));

            $this->assertNotNull($event);
            $this->assertSame('* * * * *', $event->expression);
            $this->assertSame(1440, $event->expiresAt);
        }
    }

    /** @return array{User, ConnectedWallet, ConnectedWallet} */
    private function actors(): array
    {
        $user = User::factory()->create();
        $ethereumAddress = '0x'.str_repeat('1', 40);
        $solanaAddress = '6Z7KvfSvatJqaj5kKB53TzZDxMJB8w3e2k2qz6qfYCvP';
        $ethereumWallet = ConnectedWallet::query()->create([
            'user_id' => $user->id,
            'chain' => Chain::Ethereum,
            'address' => $ethereumAddress,
            'address_hash' => ConnectedWallet::addressHash(Chain::Ethereum, $ethereumAddress),
        ]);
        $solanaWallet = ConnectedWallet::query()->create([
            'user_id' => $user->id,
            'chain' => Chain::Solana,
            'address' => $solanaAddress,
            'address_hash' => ConnectedWallet::addressHash(Chain::Solana, $solanaAddress),
        ]);

        return [$user, $ethereumWallet, $solanaWallet];
    }

    /** @param array<string, mixed> $attributes */
    private function ethereumAttempt(User $user, ConnectedWallet $wallet, array $attributes): EthereumSwapAttempt
    {
        return EthereumSwapAttempt::query()->create([...[
            'user_id' => $user->id,
            'connected_wallet_id' => $wallet->id,
            'wallet_address' => $wallet->address,
            'buy_token' => '0x'.str_repeat('2', 40),
            'sell_amount_wei' => '1000000000000000',
            'slippage_bps' => 100,
            'transaction_payload' => [],
            'status' => 'reserved',
            'expires_at' => now()->addMinutes(5),
        ], ...$attributes]);
    }

    /** @param array<string, mixed> $attributes */
    private function solanaAttempt(User $user, ConnectedWallet $wallet, array $attributes): SolanaSwapAttempt
    {
        return SolanaSwapAttempt::query()->create([...[
            'user_id' => $user->id,
            'connected_wallet_id' => $wallet->id,
            'wallet_address' => $wallet->address,
            'input_mint' => 'So11111111111111111111111111111111111111112',
            'output_mint' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
            'input_amount_lamports' => 1_000_000,
            'slippage_bps' => 100,
            'status' => 'reserved',
            'expires_at' => now()->addMinutes(5),
        ], ...$attributes]);
    }
}
