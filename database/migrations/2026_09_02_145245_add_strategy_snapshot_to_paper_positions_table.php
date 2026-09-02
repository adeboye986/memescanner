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
            $table->json('strategy_snapshot')->nullable()->after('meta');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paper_positions', function (Blueprint $table) {
            $table->dropColumn('strategy_snapshot');
        });
    }
};
