<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('token_scans', function (Blueprint $table) {
            $table->string('address')->unique();
            $table->string('symbol')->nullable();
            $table->string('name')->nullable();

            $table->decimal('price', 30, 12)->nullable();
            $table->decimal('market_cap', 30, 2)->nullable();
            $table->decimal('liquidity', 30, 2)->nullable();

            $table->unsignedBigInteger('holders')->nullable();

            $table->decimal('volume_1m', 30, 2)->nullable();
            $table->unsignedInteger('buys_1m')->nullable();
            $table->unsignedInteger('sells_1m')->nullable();

            $table->unsignedInteger('unique_wallets_5m')->nullable();

            $table->decimal('price_change_5m', 12, 4)->nullable();

            $table->unsignedInteger('score')->default(0);

            $table->json('raw_data')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('token_scans', function (Blueprint $table) {
            $table->dropColumn([
                'address',
                'symbol',
                'name',
                'price',
                'market_cap',
                'liquidity',
                'holders',
                'volume_1m',
                'buys_1m',
                'sells_1m',
                'unique_wallets_5m',
                'price_change_5m',
                'score',
                'raw_data',
                'first_seen_at',
                'last_scanned_at',
            ]);
        });
    }
};