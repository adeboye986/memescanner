<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_connection_challenges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('chain', 30);
            $table->string('address', 191);
            $table->string('provider', 50)->nullable();

            $table->string('nonce', 64)->unique();
            $table->text('message');

            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'chain']);
            $table->index(['address', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_connection_challenges');
    }
};
