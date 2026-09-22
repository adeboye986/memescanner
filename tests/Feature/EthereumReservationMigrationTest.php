<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\ConnectedWallet;
use App\Models\EthereumSwapAttempt;
use App\Models\TradeOpportunity;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EthereumReservationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_forward_migration_preserves_existing_manual_attempts_and_allows_multiple_null_links(): void
    {
        $this->migration()->down();
        $first = $this->manualAttempt();
        $second = $this->manualAttempt();
        $original = $first->getRawOriginal();

        $this->migration()->up();

        $actual = DB::table('ethereum_swap_attempts')->where('id', $first->id)->first();
        foreach ($original as $column => $value) {
            $this->assertEquals($value, $actual->{$column});
        }
        $this->assertSame(['data' => '0x1234'], $first->fresh()->transaction_payload);
        $this->assertNull($first->fresh()->trade_opportunity_id);
        $this->assertNull($second->fresh()->trade_opportunity_id);
        $this->assertDatabaseCount('ethereum_swap_attempts', 2);
    }

    public function test_empty_table_can_be_rolled_back(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('ethereum_swap_attempts', 'trade_opportunity_id'));
        $this->assertFalse(Schema::hasColumn('ethereum_swap_attempts', 'wallet_address'));
        $columns = collect(Schema::getColumns('ethereum_swap_attempts'))->keyBy('name');
        $this->assertFalse($columns['transaction_payload']['nullable']);
        $this->assertFalse($columns['expires_at']['nullable']);
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
    }

    public function test_manual_only_rollback_preserves_multiple_attempts(): void
    {
        $first = $this->manualAttempt();
        $second = $this->manualAttempt();
        $this->migration()->down();

        $this->assertDatabaseCount('ethereum_swap_attempts', 2);
        $this->assertSame($first->getRawOriginal('transaction_payload'), $first->fresh()->getRawOriginal('transaction_payload'));
        $this->assertSame($second->expires_at->toDateTimeString(), $second->fresh()->expires_at->toDateTimeString());
    }

    public function test_rollback_deletes_only_untouched_reservations_and_preserves_opportunities(): void
    {
        $manual = $this->manualAttempt();
        $opportunity = TradeOpportunity::factory()->create(['status' => 'executing']);
        $reservation = $this->reservationAttributes($manual, $opportunity);
        $id = DB::table('ethereum_swap_attempts')->insertGetId($reservation);
        $before = $opportunity->fresh()->getRawOriginal();

        $this->migration()->down();

        $this->assertDatabaseMissing('ethereum_swap_attempts', ['id' => $id]);
        $this->assertDatabaseHas('ethereum_swap_attempts', ['id' => $manual->id]);
        $this->assertSame($before, $opportunity->fresh()->getRawOriginal());
    }

    #[DataProvider('transactionStatuses')]
    public function test_rollback_preserves_real_linked_transaction_attempts(string $status): void
    {
        $attempt = $this->manualAttempt();
        $opportunity = TradeOpportunity::factory()->create();
        DB::table('ethereum_swap_attempts')->where('id', $attempt->id)->update([
            'trade_opportunity_id' => $opportunity->id,
            'wallet_address' => '0x'.str_repeat('1', 40),
            'status' => $status,
            'transaction_hash' => $status === 'prepared' ? null : '0x'.str_repeat('a', 64),
        ]);
        $before = (array) DB::table('ethereum_swap_attempts')->where('id', $attempt->id)->first();
        unset($before['trade_opportunity_id'], $before['wallet_address']);

        $this->migration()->down();

        $this->assertSame($before, (array) DB::table('ethereum_swap_attempts')->where('id', $attempt->id)->first());
        $this->assertDatabaseHas('trade_opportunities', ['id' => $opportunity->id]);
    }

    public static function transactionStatuses(): array
    {
        return [['prepared'], ['submitted'], ['confirmed'], ['failed'], ['cancelled'], ['expired']];
    }

    #[DataProvider('unsafeReservationEvidence')]
    public function test_rollback_refuses_to_delete_rows_with_any_transaction_evidence(string $field, mixed $value): void
    {
        $manual = $this->manualAttempt();
        $opportunity = TradeOpportunity::factory()->create();
        $attributes = $this->reservationAttributes($manual, $opportunity);
        $attributes[$field] = $value;
        $id = DB::table('ethereum_swap_attempts')->insertGetId($attributes);

        try {
            $this->migration()->down();
            $this->fail('Rollback discarded a record with transaction evidence.');
        } catch (LogicException) {
            $this->assertDatabaseHas('ethereum_swap_attempts', ['id' => $id]);
            $this->assertDatabaseHas('ethereum_swap_attempts', ['id' => $manual->id]);
            $this->assertTrue(Schema::hasColumn('ethereum_swap_attempts', 'trade_opportunity_id'));
        }
    }

    public static function unsafeReservationEvidence(): array
    {
        return [
            ['quote_id', 'a-real-quote'], ['transaction_payload', 'encrypted-payload'],
            ['expires_at', '2026-09-21 12:00:00'], ['transaction_hash', '0x'.str_repeat('a', 64)],
            ['submitted_at', '2026-09-21 12:00:00'], ['confirmed_at', '2026-09-21 12:00:00'],
            ['failed_at', '2026-09-21 12:00:00'], ['failure_reason', 'reverted'],
            ['block_number', '1'], ['gas_used', '21000'], ['effective_gas_price_wei', '1'], ['actual_network_fee_wei', '21000'],
            ['status', 'prepared'], ['status', 'submitted'], ['status', 'confirmed'], ['status', 'failed'],
            ['trade_opportunity_id', null],
        ];
    }

    public function test_mysql_rollback_sql_drops_foreign_key_before_unique_index(): void
    {
        $connection = new MySqlConnection(fn () => throw new LogicException('No MySQL connection is allowed in this contract test.'), 'testing', '', ['version' => '8.0.36']);
        $connection->useDefaultSchemaGrammar();
        $sql = [];
        Schema::shouldReceive('table')->once()->with('ethereum_swap_attempts', \Mockery::on(function ($callback) use ($connection, &$sql): bool {
            $sql = (new Blueprint($connection, 'ethereum_swap_attempts', $callback))->toSql();

            return true;
        }));

        $this->migration()->down();

        $this->assertStringContainsString('drop foreign key', $sql[0]);
        $this->assertStringContainsString('drop index', $sql[1]);
        $this->assertStringContainsString('trade_opportunity_id', $sql[2]);
        $this->assertStringContainsString('transaction_payload` longtext not null', implode("\n", $sql));
        $this->assertStringContainsString('expires_at` timestamp not null', implode("\n", $sql));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_21_152205_add_opportunity_reservation_to_ethereum_swap_attempts_table.php');
    }

    private function manualAttempt(): EthereumSwapAttempt
    {
        $user = User::factory()->create();
        $address = '0x'.str_pad(dechex($user->id), 40, '0', STR_PAD_LEFT);
        $wallet = ConnectedWallet::query()->create([
            'user_id' => $user->id, 'chain' => Chain::Ethereum, 'address' => $address,
            'address_hash' => ConnectedWallet::addressHash(Chain::Ethereum, $address), 'verified_at' => now(),
        ]);

        return EthereumSwapAttempt::query()->create([
            'user_id' => $user->id, 'connected_wallet_id' => $wallet->id,
            'buy_token' => '0x'.str_repeat('2', 40), 'sell_amount_wei' => '1000000000000001',
            'slippage_bps' => 100, 'quote_id' => 'quote', 'transaction_payload' => ['data' => '0x1234'],
            'status' => 'prepared', 'expires_at' => now()->addMinute(),
        ]);
    }

    /** @return array<string, mixed> */
    private function reservationAttributes(EthereumSwapAttempt $manual, TradeOpportunity $opportunity): array
    {
        return [
            'user_id' => $manual->user_id, 'connected_wallet_id' => $manual->connected_wallet_id,
            'trade_opportunity_id' => $opportunity->id, 'wallet_address' => $manual->connectedWallet->address,
            'buy_token' => $manual->buy_token, 'sell_amount_wei' => $manual->sell_amount_wei,
            'slippage_bps' => 100, 'status' => 'reserved', 'transaction_payload' => null, 'expires_at' => null,
        ];
    }
}
