<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('token_scans', function (Blueprint $table) {
            $table->id();

            $table->string('address')->unique();
            $table->string('symbol')->nullable();
            $table->string('name')->nullable();

            $table->decimal('price', 30, 12)->nullable();
            $table->decimal('market_cap', 30, 2)->nullable();
            $table->decimal('liquidity', 30, 2)->nullable();

            $table->unsignedBigInteger('holders')->nullable();

            $table->decimal('volume_5m', 30, 2)->nullable();
            $table->unsignedInteger('buys_5m')->nullable();
            $table->unsignedInteger('sells_5m')->nullable();

            $table->decimal('price_change_5m', 12, 4)->nullable();

            $table->unsignedInteger('score')->default(0);

            $table->json('raw_data')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_scanned_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('token_scans');
    }
};
