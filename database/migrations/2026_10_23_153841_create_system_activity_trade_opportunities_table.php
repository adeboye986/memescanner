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
        Schema::create('system_activity_trade_opportunities', function (Blueprint $table) {
            $table->foreignId('system_activity_id')->constrained()->restrictOnDelete();
            $table->foreignId('trade_opportunity_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->primary(['system_activity_id', 'trade_opportunity_id'], 'system_activity_trade_opportunities_pk');
            $table->index(['trade_opportunity_id', 'system_activity_id'], 'system_activity_trade_opportunities_opportunity_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_activity_trade_opportunities');
    }
};
