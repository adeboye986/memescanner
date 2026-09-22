<?php

namespace Tests\Feature;

use App\Models\ConnectedWallet;
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

class EthereumPreparationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_rollback_and_forward_migration(): void
    {
        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('ethereum_swap_attempts', 'preparation_token'));
        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('ethereum_swap_attempts', 'preparation_token'));
    }

    #[DataProvider('states')]
    public function test_rollback_preserves_transactions_and_normalizes_only_phase_two_intentions(string $status, bool $stale, string $expected, string $opStatus): void
    {
        [$id, $opportunity] = $this->row($status, $stale);
        $before = (array) DB::table('ethereum_swap_attempts')->find($id);
        $this->migration()->down();
        $after = (array) DB::table('ethereum_swap_attempts')->find($id);
        $this->assertSame($expected, $after['status']);
        $this->assertSame($opStatus, $opportunity->fresh()->status->value);
        foreach (['transaction_payload', 'transaction_hash', 'expires_at', 'quote_id', 'user_id', 'trade_opportunity_id', 'wallet_address'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
        $this->assertFalse(Schema::hasColumn('ethereum_swap_attempts', 'preparation_expires_at'));
    }

    public static function states(): array
    {
        return [['reserved', false, 'reserved', 'executing'], ['preparing', false, 'reserved', 'executing'],
            ['preparing', true, 'expired', 'expired'], ['released', false, 'expired', 'expired'], ['released', true, 'expired', 'expired'],
            ['prepared', false, 'prepared', 'executing'], ['submitted', false, 'submitted', 'executing'],
            ['confirmed', false, 'confirmed', 'executing'], ['failed', false, 'failed', 'executing']];
    }

    #[DataProvider('evidence')]
    public function test_rollback_refuses_transaction_evidence_before_any_normalization(string $field, mixed $value): void
    {
        [$first] = $this->row('preparing', false);
        [$second] = $this->row('released', false);
        DB::table('ethereum_swap_attempts')->where('id', $second)->update([$field => $value]);
        try {
            $this->migration()->down();
            $this->fail('Unsafe rollback should be refused.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('transaction evidence', $exception->getMessage());
        }
        $this->assertSame('preparing', DB::table('ethereum_swap_attempts')->find($first)->status);
        $this->assertSame($value, DB::table('ethereum_swap_attempts')->find($second)->{$field});
        $this->assertTrue(Schema::hasColumn('ethereum_swap_attempts', 'preparation_token'));
    }

    public static function evidence(): array
    {
        return [['transaction_hash', '0x'.str_repeat('a', 64)], ['transaction_payload', 'ciphertext'], ['quote_id', 'quote'],
            ['expires_at', '2026-09-22 12:00:00'], ['submitted_at', '2026-09-22 12:00:00'], ['gas_used', '21000']];
    }

    public function test_terminal_opportunity_is_not_resurrected(): void
    {
        [$id, $opportunity] = $this->row('preparing', false);
        $opportunity->update(['status' => 'ignored']);
        $this->migration()->down();
        $this->assertSame('expired', DB::table('ethereum_swap_attempts')->find($id)->status);
        $this->assertSame('ignored', $opportunity->fresh()->status->value);
    }

    public function test_manual_transaction_survives_rollback_unchanged(): void
    {
        [$id, $opportunity] = $this->row('prepared', false);
        DB::table('ethereum_swap_attempts')->where('id', $id)->update(['trade_opportunity_id' => null]);
        $this->migration()->down();
        $this->assertSame('preserved-ciphertext', DB::table('ethereum_swap_attempts')->find($id)->transaction_payload);
        $this->assertNull(DB::table('ethereum_swap_attempts')->find($id)->trade_opportunity_id);
        $this->assertSame('executing', $opportunity->fresh()->status->value);
    }

    public function test_mysql_schema_rollback_drops_only_phase_two_columns(): void
    {
        $connection = new MySqlConnection(fn () => throw new LogicException('No MySQL connection allowed.'), 'testing', '', ['version' => '8.0.36']);
        $connection->useDefaultSchemaGrammar();
        $sql = [];
        Schema::shouldReceive('table')->once()->with('ethereum_swap_attempts', \Mockery::on(function ($callback) use ($connection, &$sql): bool {
            $sql = (new Blueprint($connection, 'ethereum_swap_attempts', $callback))->toSql();

            return true;
        }));
        $this->migration()->down();
        $this->assertCount(1, $sql);
        $this->assertSame('alter table ethereum_swap_attempts drop preparation_token, drop preparation_expires_at, drop revalidation_data', str_replace(chr(96), '', $sql[0]));
    }

    private function row(string $status, bool $stale): array
    {
        $user = User::factory()->create();
        $address = '0x'.str_pad(dechex($user->id), 40, '0', STR_PAD_LEFT);
        $wallet = ConnectedWallet::query()->create(['user_id' => $user->id, 'chain' => 'ethereum', 'address' => $address, 'address_hash' => ConnectedWallet::addressHash('ethereum', $address), 'verified_at' => now()]);
        $opportunity = TradeOpportunity::factory()->create(['user_id' => $user->id, 'chain' => 'ethereum',
            'status' => $status === 'released' ? 'pending_confirmation' : 'executing', 'qualified_at' => $stale ? now()->subMinutes(6) : now()]);
        $transaction = in_array($status, ['prepared', 'submitted', 'confirmed', 'failed'], true);
        $id = DB::table('ethereum_swap_attempts')->insertGetId(['user_id' => $user->id, 'connected_wallet_id' => $wallet->id,
            'trade_opportunity_id' => $opportunity->id, 'wallet_address' => $wallet->address, 'buy_token' => '0x'.str_repeat('2', 40),
            'sell_amount_wei' => '1000', 'slippage_bps' => 100, 'status' => $status,
            'transaction_payload' => $transaction ? 'preserved-ciphertext' : null, 'expires_at' => $transaction ? now()->addMinute() : null,
            'transaction_hash' => in_array($status, ['submitted', 'confirmed', 'failed'], true) ? '0x'.str_repeat('a', 64) : null,
            'preparation_token' => $status === 'preparing' ? 'lease' : null, 'preparation_expires_at' => $status === 'preparing' ? now()->addMinutes(3) : null]);

        return [$id, $opportunity];
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_22_094148_add_preparation_lease_to_ethereum_swap_attempts_table.php');
    }
}
