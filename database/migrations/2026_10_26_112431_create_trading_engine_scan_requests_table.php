<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_engine_scan_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('system_activity_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('scanner', 32);
            $table->string('network_id', 64);
            $table->string('entry_mode', 16);
            $table->string('idempotency_key', 128)->unique();
            $table->char('payload_sha256', 64);
            $table->json('payload');
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('recovery_count')->default(0);
            $table->string('engine_operation_id', 26)->nullable()->unique();
            $table->string('engine_scan_execution_id', 26)->nullable()->unique();
            $table->string('last_error_code', 128)->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('submitting_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('acceptance_unknown_at')->nullable();
            $table->timestampTz('definitively_rejected_at')->nullable();
            $table->timestampTz('last_recovered_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('projected_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'submitting_at'], 'te_scan_request_stale_index');
            $table->index(['status', 'last_recovered_at'], 'te_scan_request_recovery_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_engine_scan_requests');
    }
};
