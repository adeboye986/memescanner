<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_wallets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('chain', 30);
            $table->string('address', 191);

            $table->string('address_hash', 64)->unique();

            $table->string('provider', 50)->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'chain']);
            $table->index(['chain', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_wallets');
    }
};
