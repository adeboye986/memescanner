<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('chain', 30);
            $table->string('network', 30);
            $table->string('wallet_address', 191);
            $table->foreignId('connected_wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('trade_opportunity_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('ethereum_swap_attempt_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('token_address', 191);
            $table->string('entry_transaction_hash', 191);
            $table->string('entry_block_number', 78);
            $table->timestamp('entry_confirmed_at');
            $table->string('accounting_status', 30)->default('pending');
            $table->string('acquired_raw_amount', 78)->nullable();
            $table->unsignedTinyInteger('token_decimals')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'chain', 'accounting_status']);
        });
    }

    /** Stop writers before rollback. Never drop retained LIVE execution history. */
    public function down(): void
    {
        if (DB::table('live_positions')->exists()) {
            throw new LogicException('Cannot remove live_positions while LIVE execution history exists.');
        }
        Schema::dropIfExists('live_positions');
    }
};
