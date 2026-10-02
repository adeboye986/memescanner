<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_engine_paper_position_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paper_position_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('trade_opportunity_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->char('engine_opportunity_id', 26);
            $table->char('engine_position_id', 26)->nullable()->unique();
            $table->string('chain', 24);
            $table->string('asset_address', 128);
            $table->string('ownership_state', 24)->default('pending_registration');
            $table->unsignedInteger('next_observation_sequence')->default(1);
            $table->json('ownership_snapshot');
            $table->json('registration_payload');
            $table->char('registration_payload_sha256', 64);
            $table->string('registration_idempotency_key', 128)->unique();
            $table->string('registration_error_code', 128)->nullable();
            $table->timestampTz('registered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('trading_engine_paper_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('position_link_id')->constrained('trading_engine_paper_position_links')->restrictOnDelete();
            $table->foreignId('paper_position_id')->constrained()->restrictOnDelete();
            $table->string('observation_id', 128)->unique();
            $table->unsignedInteger('observation_sequence');
            $table->json('payload');
            $table->char('payload_sha256', 64);
            $table->string('idempotency_key', 128)->unique();
            $table->string('status', 24)->default('pending');
            $table->char('engine_decision_id', 26)->nullable();
            $table->string('engine_decision', 8)->nullable();
            $table->string('error_code', 128)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['position_link_id', 'observation_sequence'], 'te_paper_observation_sequence_unique');
        });

        Schema::create('trading_engine_paper_lifecycle_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('position_link_id')->constrained('trading_engine_paper_position_links')->restrictOnDelete();
            $table->foreignId('observation_id')->nullable()->constrained('trading_engine_paper_observations')->restrictOnDelete();
            $table->char('event_id', 26)->unique();
            $table->char('decision_id', 26)->unique();
            $table->unsignedInteger('lifecycle_version');
            $table->string('decision', 8);
            $table->string('exit_type', 32)->nullable();
            $table->decimal('observed_multiple', 30, 18);
            $table->decimal('trigger_multiple', 30, 18)->nullable();
            $table->decimal('observed_market_cap', 30, 8);
            $table->decimal('observed_price', 36, 24)->nullable();
            $table->decimal('observed_liquidity', 30, 8)->nullable();
            $table->decimal('peak_market_cap', 30, 8);
            $table->decimal('peak_multiple', 30, 18);
            $table->decimal('drawdown_percent', 30, 18);
            $table->string('protection_before', 16);
            $table->string('protection_after', 16);
            $table->json('transitions');
            $table->json('payload');
            $table->char('payload_sha256', 64);
            $table->timestampTz('occurred_at');
            $table->timestampTz('projected_at');
            $table->timestamps();
            $table->unique(['position_link_id', 'lifecycle_version'], 'te_paper_decision_version_unique');
        });

        Schema::create('trading_engine_paper_exit_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('position_link_id')->constrained('trading_engine_paper_position_links')->restrictOnDelete();
            $table->foreignId('lifecycle_decision_id')->unique()->constrained('trading_engine_paper_lifecycle_decisions')->restrictOnDelete();
            $table->foreignId('paper_position_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('paper_wallet_id')->constrained()->restrictOnDelete();
            $table->char('exit_event_id', 26)->unique();
            $table->decimal('fill_multiple', 30, 18);
            $table->decimal('cost_basis_native', 24, 8);
            $table->decimal('proceeds_native', 24, 8);
            $table->decimal('realized_pnl_native', 24, 8);
            $table->timestampTz('settled_at');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['trading_engine_paper_lifecycle_decisions', 'trading_engine_paper_exit_settlements'] as $table) {
                DB::statement("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'engine PAPER evidence is append-only'); END");
                DB::statement("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'engine PAPER evidence is append-only'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS trading_engine_paper_lifecycle_decisions_no_update');
            DB::statement('DROP TRIGGER IF EXISTS trading_engine_paper_lifecycle_decisions_no_delete');
            DB::statement('DROP TRIGGER IF EXISTS trading_engine_paper_exit_settlements_no_update');
            DB::statement('DROP TRIGGER IF EXISTS trading_engine_paper_exit_settlements_no_delete');
        }

        Schema::dropIfExists('trading_engine_paper_exit_settlements');
        Schema::dropIfExists('trading_engine_paper_lifecycle_decisions');
        Schema::dropIfExists('trading_engine_paper_observations');
        Schema::dropIfExists('trading_engine_paper_position_links');
    }
};
