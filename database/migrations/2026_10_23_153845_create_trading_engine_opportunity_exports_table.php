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
        Schema::create('trading_engine_opportunity_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_opportunity_id')->unique()->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 128)->unique();
            $table->string('status', 32)->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('last_error_code', 128)->nullable();
            $table->char('engine_operation_id', 26)->nullable();
            $table->char('engine_opportunity_id', 26)->nullable();
            $table->char('engine_event_id', 26)->nullable();
            $table->boolean('duplicate')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('submitting_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->timestamp('permanently_failed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at'], 'trading_engine_opportunity_exports_status_updated_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trading_engine_opportunity_exports');
    }
};
