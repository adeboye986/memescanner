<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('token_scans', function (Blueprint $table) {
            $table->decimal('volume_1m', 30, 2)->nullable();
            $table->unsignedInteger('buys_1m')->nullable();
            $table->unsignedInteger('sells_1m')->nullable();
            $table->unsignedInteger('unique_wallets_5m')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('token_scans', function (Blueprint $table) {
            $table->dropColumn([
                'volume_1m',
                'buys_1m',
                'sells_1m',
                'unique_wallets_5m',
            ]);
        });
    }
};