<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solana_swap_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connected_wallet_id')->constrained()->cascadeOnDelete();
            $table->string('request_id', 191)->unique();
            $table->string('output_mint', 191);
            $table->unsignedBigInteger('input_amount_lamports');
            $table->unsignedInteger('slippage_bps');
            $table->string('message_hash', 64);
            $table->longText('prepared_transaction');
            $table->string('status', 30)->default('prepared');
            $table->string('transaction_signature', 191)->nullable()->unique();
            $table->string('provider_error_code', 100)->nullable();
            $table->text('provider_error_message')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solana_swap_attempts');
    }
};
