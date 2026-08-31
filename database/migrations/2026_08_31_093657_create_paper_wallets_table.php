<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('paper_wallets', function (Blueprint $table) {
            $table->id();

            $table->string('name')->default('default');

            $table->decimal('starting_balance_sol', 16, 8)->default(5);
            $table->decimal('available_balance_sol', 16, 8)->default(5);
            $table->decimal('invested_balance_sol', 16, 8)->default(0);

            $table->decimal('realized_pnl_sol', 16, 8)->default(0);

            $table->timestamps();

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_wallets');
    }
};