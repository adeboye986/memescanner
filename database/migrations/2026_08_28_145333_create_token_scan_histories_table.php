<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_scan_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('token_scan_id')
                ->constrained('token_scans')
                ->cascadeOnDelete();

            $table->string('address')->index();
            $table->string('symbol')->nullable();
            $table->string('name')->nullable();

            $table->string('snapshot_type')->default('follow_up');

            $table->decimal('price', 30, 18)->nullable();
            $table->decimal('market_cap', 24, 8)->nullable();
            $table->decimal('liquidity', 24, 8)->nullable();

            $table->unsignedInteger('holders')->nullable();

            $table->decimal('volume_1m', 24, 8)->nullable();
            $table->unsignedInteger('buys_1m')->nullable();
            $table->unsignedInteger('sells_1m')->nullable();

            $table->unsignedInteger('unique_wallets_5m')->nullable();

            $table->decimal('price_change_5m', 12, 4)->nullable();

            $table->unsignedTinyInteger('score')->nullable();

            // DexScreener snapshot
            $table->boolean('dex_available')->default(false);
            $table->string('dex')->nullable();
            $table->string('dex_pair_address')->nullable();

            $table->decimal('dex_market_cap', 24, 8)->nullable();
            $table->decimal('dex_liquidity', 24, 8)->nullable();

            $table->unsignedInteger('dex_pair_age_minutes')->nullable();

            $table->json('raw_data')->nullable();
            $table->timestamp('scanned_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_scan_histories');
    }
};