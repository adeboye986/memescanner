<?php

namespace Tests\Feature;

use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Models\TokenScan;
use App\Services\BirdeyeService;
use App\Services\DexScreenerService;
use App\Services\GoPlusService;
use App\Services\PaperTradeEntryService;
use App\Services\TelegramService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class NewTokenScanEntryTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
        $this->createScannerTables();
        config()->set('services.trading.paper_trading', true);
        config()->set('services.trading.paper_trade_size_sol', 0.1);
        PaperWallet::query()->create([
            'name' => 'default', 'starting_balance_sol' => 5,
            'available_balance_sol' => 5, 'invested_balance_sol' => 0,
            'realized_pnl_sol' => 0,
        ]);
    }

    public function test_only_verified_strong_candidates_buy_and_candidate_alert_precedes_buy_alert(): void
    {
        $tokens = [
            'unverified' => $this->token('UNVERIFIED', 5_000, 50, 9, 1, 30, 10),
            'watch' => $this->token('WATCH', 5_000, 20, 5, 5, 0, 1),
            'strong' => $this->token('STRONG', 2_500, 20, 7, 3, 15, 1),
            'rejected' => $this->token('REJECTED', 2_500, 20, 7, 3, 15, 1),
        ];
        $birdeye = $this->mock(BirdeyeService::class);
        $birdeye->shouldReceive('newListings')->once()->andReturn(['data' => ['items' => array_map(
            fn (string $address): array => ['address' => $address, 'symbol' => strtoupper($address), 'name' => ucfirst($address), 'liquidity' => 1_000],
            array_keys($tokens),
        )]]);

        foreach ($tokens as $address => $token) {
            $birdeye->shouldReceive('tokenOverview')->with($address)->twice()->andReturn(['data' => $token]);
        }

        $goplus = $this->mock(GoPlusService::class);
        $goplus->shouldReceive('evaluateToken')->with('unverified')->once()->andReturn([
            'passed' => false, 'score' => 0, 'risks' => ['No GoPlus security data returned'],
        ]);
        $goplus->shouldReceive('evaluateToken')->with('watch')->once()->andReturn(['passed' => true, 'score' => 100, 'risks' => []]);
        $goplus->shouldReceive('evaluateToken')->with('strong')->once()->andReturn(['passed' => true, 'score' => 100, 'risks' => []]);
        $goplus->shouldReceive('evaluateToken')->with('rejected')->once()->andReturn(['passed' => true, 'score' => 100, 'risks' => []]);

        $dex = $this->mock(DexScreenerService::class);
        foreach (['unverified', 'watch', 'strong'] as $address) {
            $dex->shouldReceive('analyzeToken')->with($address)->once()->andReturn($this->dexData($address));
        }
        $dex->shouldReceive('analyzeToken')->with('rejected')->once()->andReturn([
            'available' => false,
            'requested_token_is_base' => false,
        ]);

        $messages = [];
        $this->mock(TelegramService::class)->shouldReceive('send')->times(6)
            ->withArgs(function (string $message) use (&$messages): bool {
                $messages[] = $message;

                return true;
            });

        $this->artisan('tokens:scan')->assertSuccessful();

        $this->assertSame(
            ['STRONG', 'REJECTED'],
            PaperPosition::query()->orderBy('id')->pluck('symbol')->all()
        );
        $this->assertStringContainsString('UNVERIFIED CANDIDATE', $messages[0]);
        $this->assertStringContainsString('Security unverified — no paper trade opened.', $messages[0]);
        $this->assertStringContainsString('WATCHLIST', $messages[1]);
        $this->assertStringContainsString('Scanner watchlist only — no paper trade opened.', $messages[1]);
        $this->assertStringContainsString('STRONG CANDIDATE', $messages[2]);
        $this->assertStringContainsString('PAPER BUY EXECUTED', $messages[3]);
        $this->assertStringContainsString('Scanner:</b> NEW TOKEN', $messages[3]);
        $this->assertStringContainsString('Chain:</b> SOLANA', $messages[3]);
        $this->assertStringContainsString('Wallet Invested:</b> 0.1000 SOL', $messages[3]);
        $this->assertStringContainsString('STRONG CANDIDATE', $messages[4]);
        $this->assertStringContainsString('provisional funded paper entry executed using fresh Birdeye data', $messages[4]);
        $this->assertStringContainsString('PAPER BUY EXECUTED', $messages[5]);
        $this->assertSame('skipped', TokenScan::query()->where('address', 'watch')->sole()->raw_data['scanner_decision']['paper_entry_status']);
        $this->assertSame('Security is unverified.', TokenScan::query()->where('address', 'unverified')->sole()->raw_data['scanner_decision']['paper_entry_reason']);
        $this->assertSame('executed', TokenScan::query()->where('address', 'strong')->sole()->raw_data['scanner_decision']['paper_entry_status']);
        $provisionalScan = TokenScan::query()->where('address', 'rejected')->sole();
        $this->assertSame('executed', $provisionalScan->raw_data['scanner_decision']['paper_entry_status']);
        $this->assertSame(
            'Funded provisional paper position created using fresh Birdeye data; Dex confirmation pending.',
            $provisionalScan->raw_data['scanner_decision']['paper_entry_reason']
        );
        TokenScan::query()->each(function (TokenScan $scan): void {
            $this->assertIsArray($scan->raw_data);
            $this->assertIsArray(data_get($scan->raw_data, 'scanner_decision'));
        });
        $this->assertEqualsWithDelta(4.8, (float) PaperWallet::query()->sole()->available_balance_sol, 0.000001);
    }

    public function test_historical_raw_data_remains_a_readable_array_without_double_encoding(): void
    {
        $scan = TokenScan::query()->create([
            'chain' => 'solana',
            'address' => 'historical',
            'score' => 10,
            'raw_data' => ['legacy' => ['readable' => true]],
        ])->fresh();

        $this->assertIsArray($scan->raw_data);
        $this->assertTrue(data_get($scan->raw_data, 'legacy.readable'));
        $this->assertIsArray(json_decode((string) $scan->getRawOriginal('raw_data'), true));
    }

    public function test_duplicate_and_rejected_entries_do_not_send_false_buy_notifications(): void
    {
        $messages = [];
        $this->mock(TelegramService::class)->shouldReceive('send')->once()
            ->withArgs(function (string $message) use (&$messages): bool {
                $messages[] = $message;

                return true;
            });
        $entries = app(PaperTradeEntryService::class);
        $entry = [
            'address' => 'duplicate', 'symbol' => 'DUP', 'name' => 'Duplicate',
            'entry_market_cap' => 10_000, 'scanner' => 'new-token',
            'send_notification' => true,
        ];

        $entries->buy($entry);
        $entries->buy($entry);
        PaperWallet::query()->update(['available_balance_sol' => 0]);

        try {
            $entries->buy(array_merge($entry, ['address' => 'rejected']));
            $this->fail('Expected the insufficient-balance entry to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Insufficient paper SOL balance.', $exception->getMessage());
        }

        $this->assertCount(1, $messages);
        $this->assertSame(1, PaperPosition::query()->count());
    }

    /** @return array<string, mixed> */
    private function token(string $symbol, float $liquidity, int $holders, int $buys, int $sells, int $wallets, float $change): array
    {
        return [
            'symbol' => $symbol, 'name' => ucfirst(strtolower($symbol)), 'price' => 0.001,
            'marketCap' => 10_000, 'liquidity' => $liquidity, 'holder' => $holders,
            'v1m' => 1_000, 'buy1m' => $buys, 'sell1m' => $sells,
            'uniqueWallet5m' => $wallets, 'priceChange5mPercent' => $change,
        ];
    }

    /** @return array<string, mixed> */
    private function dexData(string $address): array
    {
        return [
            'available' => true, 'requested_token_is_base' => true,
            'requested_token_address' => $address, 'market_cap' => 11_000,
            'price_usd' => 0.0011, 'liquidity_usd' => 3_000,
            'pair_address' => 'pair-'.$address, 'pair_age_minutes' => 5, 'dex' => 'test-dex',
        ];
    }

    private function createScannerTables(): void
    {
        Schema::create('token_scans', function (Blueprint $table): void {
            $table->id();
            $table->string('chain')->default('solana');
            $table->string('address');
            $table->string('symbol')->nullable();
            $table->string('name')->nullable();
            $table->decimal('price', 30, 12)->nullable();
            $table->decimal('market_cap', 30, 2)->nullable();
            $table->decimal('liquidity', 30, 2)->nullable();
            $table->unsignedBigInteger('holders')->nullable();
            $table->decimal('volume_1m', 30, 2)->nullable();
            $table->unsignedInteger('buys_1m')->nullable();
            $table->unsignedInteger('sells_1m')->nullable();
            $table->unsignedInteger('unique_wallets_5m')->nullable();
            $table->decimal('price_change_5m', 12, 4)->nullable();
            $table->unsignedInteger('score')->default(0);
            $table->json('raw_data')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->unsignedInteger('security_score')->nullable();
            $table->boolean('security_passed')->nullable();
            $table->json('security_risks')->nullable();
            $table->string('follow_up_status')->nullable();
            $table->timestamp('last_follow_up_alerted_at')->nullable();
            $table->timestamps();
            $table->unique(['chain', 'address']);
        });

        Schema::create('token_scan_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('token_scan_id')->constrained()->cascadeOnDelete();
            $table->string('address');
            $table->string('symbol')->nullable();
            $table->string('name')->nullable();
            $table->string('snapshot_type');
            $table->decimal('price', 30, 18)->nullable();
            $table->decimal('market_cap', 24, 8)->nullable();
            $table->decimal('liquidity', 24, 8)->nullable();
            $table->unsignedInteger('holders')->nullable();
            $table->decimal('volume_1m', 24, 8)->nullable();
            $table->unsignedInteger('buys_1m')->nullable();
            $table->unsignedInteger('sells_1m')->nullable();
            $table->unsignedInteger('unique_wallets_5m')->nullable();
            $table->decimal('price_change_5m', 12, 4)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->boolean('dex_available')->default(false);
            $table->string('dex')->nullable();
            $table->string('dex_pair_address')->nullable();
            $table->decimal('dex_market_cap', 24, 8)->nullable();
            $table->decimal('dex_liquidity', 24, 8)->nullable();
            $table->unsignedInteger('dex_pair_age_minutes')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamp('scanned_at');
            $table->timestamps();
        });
    }
}
