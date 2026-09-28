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
        Schema::create('trading_engine_event_inbox', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 26)->unique();
            $table->string('event_type', 128)->index();
            $table->unsignedInteger('schema_version');
            $table->timestampTz('occurred_at');
            $table->string('producer', 64);
            $table->string('aggregate_type', 64);
            $table->string('aggregate_id', 26);
            $table->unsignedBigInteger('aggregate_version');
            $table->string('correlation_id', 128);
            $table->string('causation_id', 128);
            $table->string('idempotency_key', 128);
            $table->string('traceparent', 55);
            $table->char('payload_sha256', 64);
            $table->char('raw_body_sha256', 64);
            $table->json('event_envelope');
            $table->json('payload');
            $table->string('handling_status', 32)->index();
            $table->timestampTz('received_at')->index();
            $table->timestampTz('handled_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trading_engine_event_inbox');
    }
};
