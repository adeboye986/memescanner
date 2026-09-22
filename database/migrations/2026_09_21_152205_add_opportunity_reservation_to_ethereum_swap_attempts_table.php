<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ethereum_swap_attempts', function (Blueprint $table): void {
            $table->foreignId('trade_opportunity_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('wallet_address', 42)->nullable();
            $table->longText('transaction_payload')->nullable()->change();
            $table->timestamp('expires_at')->nullable()->change();
        });
    }

    /**
     * Roll back with application writers stopped. Discard the new linkage/snapshot
     * columns and only linked reservations with no preparation or transaction evidence.
     * These intentions cannot satisfy the old NOT NULL schema; never invent payloads
     * or expiries. Preserve all opportunities and every real transaction attempt.
     */
    public function down(): void
    {
        $reservations = DB::table('ethereum_swap_attempts')
            ->whereNotNull('trade_opportunity_id')
            ->where('status', 'reserved');
        foreach (['quote_id', 'transaction_payload', 'expires_at', 'transaction_hash', 'submitted_at',
            'confirmed_at', 'failed_at', 'failure_reason', 'block_number', 'gas_used',
            'effective_gas_price_wei', 'actual_network_fee_wei'] as $column) {
            $reservations->whereNull($column);
        }

        if (DB::table('ethereum_swap_attempts')
            ->where(fn (Builder $query): Builder => $query->whereNull('transaction_payload')->orWhereNull('expires_at'))
            ->whereNotIn('id', (clone $reservations)->select('id'))->exists()) {
            throw new LogicException('Cannot restore the previous schema: a retained attempt has missing transaction payload or expiry. Preserve and repair this record before rollback.');
        }

        $reservations->delete();

        Schema::table('ethereum_swap_attempts', function (Blueprint $table): void {
            $table->dropForeign(['trade_opportunity_id']);
            $table->dropUnique(['trade_opportunity_id']);
            $table->dropColumn(['trade_opportunity_id', 'wallet_address']);
            $table->longText('transaction_payload')->nullable(false)->change();
            $table->timestamp('expires_at')->nullable(false)->change();
        });
    }
};
