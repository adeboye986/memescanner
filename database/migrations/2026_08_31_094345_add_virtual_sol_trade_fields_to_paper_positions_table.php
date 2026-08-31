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
        Schema::table('paper_positions', function (Blueprint $table) {
            $table->decimal('initial_investment_sol', 16, 8)
                ->default(0);

            $table->decimal('remaining_investment_sol', 16, 8)
                ->default(0);

            $table->decimal('realized_sol', 16, 8)
                ->default(0);

            $table->decimal('trade_pnl_sol', 16, 8)
                ->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paper_positions', function (Blueprint $table) {
            $table->dropColumn([
                'initial_investment_sol',
                'remaining_investment_sol',
                'realized_sol',
                'trade_pnl_sol',
            ]);
        });
    }
};
