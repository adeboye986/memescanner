<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trading_engine_opportunity_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_link_id')
                ->constrained('trading_engine_opportunity_links')
                ->restrictOnDelete();
            $table->foreignId('trade_opportunity_id')->constrained()->restrictOnDelete();
            $table->char('evaluation_id', 26)->unique();
            $table->char('engine_opportunity_id', 26);
            $table->char('evaluation_event_id', 26)->unique();
            $table->char('recorded_event_id', 26);
            $table->string('policy_key', 128);
            $table->unsignedInteger('policy_version');
            $table->string('algorithm_key', 128);
            $table->unsignedInteger('algorithm_version');
            $table->char('policy_definition_sha256', 64);
            $table->char('source_request_sha256', 64);
            $table->char('evaluation_input_sha256', 64);
            $table->char('result_sha256', 64);
            $table->string('outcome', 32);
            $table->json('reason_codes');
            $table->json('advisory_codes');
            $table->json('evidence');
            $table->string('correlation_id', 128);
            $table->string('traceparent', 55);
            $table->timestampTz('evaluated_at');
            $table->timestampTz('event_received_at');
            $table->timestampTz('projected_at');

            $table->foreign('evaluation_event_id', 'te_opp_eval_event_fk')
                ->references('event_id')
                ->on('trading_engine_event_inbox')
                ->restrictOnDelete();
            $table->foreign('recorded_event_id', 'te_opp_eval_recorded_event_fk')
                ->references('event_id')
                ->on('trading_engine_event_inbox')
                ->restrictOnDelete();
            $table->unique(
                ['engine_opportunity_id', 'policy_key', 'policy_version'],
                'te_opp_eval_engine_policy_unique',
            );
            $table->index(['trade_opportunity_id', 'evaluated_at'], 'te_opp_eval_laravel_time_idx');
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trading_engine_opportunity_evaluations_no_update
                BEFORE UPDATE ON trading_engine_opportunity_evaluations
                BEGIN
                    SELECT RAISE(ABORT, 'trading engine opportunity evaluations are append-only');
                END
                SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER trading_engine_opportunity_evaluations_no_delete
                BEFORE DELETE ON trading_engine_opportunity_evaluations
                BEGIN
                    SELECT RAISE(ABORT, 'trading engine opportunity evaluations are append-only');
                END
                SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS trading_engine_opportunity_evaluations_no_update');
            DB::statement('DROP TRIGGER IF EXISTS trading_engine_opportunity_evaluations_no_delete');
        }

        Schema::dropIfExists('trading_engine_opportunity_evaluations');
    }
};
