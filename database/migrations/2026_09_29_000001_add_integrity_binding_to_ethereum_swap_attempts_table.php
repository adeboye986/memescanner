<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ethereum_swap_attempts', function (Blueprint $table): void {
            $table->string('preparation_origin', 64)->nullable();
            $table->string('preparation_binding_sha256', 64)->nullable();
        });
    }

    /** Stop writers first; never remove an unresolved engine-prepared integrity binding. */
    public function down(): void
    {
        if (DB::table('ethereum_swap_attempts')
            ->where('preparation_origin', 'trading_engine_live_preparation')
            ->whereNull('transaction_hash')
            ->whereNotIn('status', ['cancelled', 'failed'])
            ->exists()) {
            throw new LogicException('Resolve engine-prepared Ethereum attempts before removing their integrity bindings.');
        }

        Schema::table('ethereum_swap_attempts', function (Blueprint $table): void {
            $table->dropColumn(['preparation_origin', 'preparation_binding_sha256']);
        });
    }
};
