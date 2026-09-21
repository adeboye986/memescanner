<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ethereum_swap_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connected_wallet_id')->constrained()->cascadeOnDelete();
            $table->string('buy_token', 42);
            $table->string('sell_amount_wei', 78);
            $table->unsignedInteger('slippage_bps');
            $table->string('quote_id', 100)->nullable();
            $table->longText('transaction_payload');
            $table->string('status', 30)->default('prepared');
            $table->string('transaction_hash', 66)->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ethereum_swap_attempts');
    }
};
