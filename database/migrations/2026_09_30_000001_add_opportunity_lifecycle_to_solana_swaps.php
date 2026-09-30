<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solana_swap_attempts', function (Blueprint $table): void {
            $table->string('request_id', 191)->nullable()->change();
            $table->string('message_hash', 64)->nullable()->change();
            $table->longText('prepared_transaction')->nullable()->change();
            $table->timestamp('expires_at')->nullable()->change();
            $table->foreignId('trade_opportunity_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('wallet_address', 191)->nullable();
            $table->string('input_mint', 191)->nullable();
            $table->string('network', 30)->nullable();
            $table->uuid('preparation_token')->nullable();
            $table->timestamp('preparation_expires_at')->nullable();
            $table->string('preparation_origin', 64)->nullable();
            $table->string('preparation_binding_sha256', 64)->nullable();
            $table->timestamp('signing_requested_at')->nullable();
            $table->string('signing_claim_hash', 64)->nullable();
            $table->timestamp('signing_armed_at')->nullable();
            $table->string('acquired_raw_amount', 78)->nullable();
            $table->unsignedTinyInteger('token_decimals')->nullable();
        });

        Schema::table('live_positions', function (Blueprint $table): void {
            $table->foreignId('solana_swap_attempt_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    /** Stop writers first; retained LIVE execution evidence must not be orphaned. */
    public function down(): void
    {
        if (DB::table('solana_swap_attempts')->whereNotNull('trade_opportunity_id')->exists()
            || DB::table('live_positions')->whereNotNull('solana_swap_attempt_id')->exists()) {
            throw new LogicException('Resolve retained Solana opportunity executions before removing their lifecycle schema.');
        }

        Schema::table('live_positions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('solana_swap_attempt_id');
        });
        Schema::table('solana_swap_attempts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('trade_opportunity_id');
            $table->dropColumn([
                'wallet_address', 'input_mint', 'network', 'preparation_token', 'preparation_expires_at',
                'preparation_origin', 'preparation_binding_sha256', 'signing_requested_at',
                'signing_claim_hash', 'signing_armed_at', 'acquired_raw_amount', 'token_decimals',
            ]);
            $table->string('request_id', 191)->nullable(false)->change();
            $table->string('message_hash', 64)->nullable(false)->change();
            $table->longText('prepared_transaction')->nullable(false)->change();
            $table->timestamp('expires_at')->nullable(false)->change();
        });
    }
};
