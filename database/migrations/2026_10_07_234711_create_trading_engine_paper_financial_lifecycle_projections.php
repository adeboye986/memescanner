<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_engine_paper_wallet_projections', function (Blueprint $table): void {
            $table->string('realized_pnl_native', 80)->default('0');
        });

        Schema::create('trading_engine_paper_position_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('position_projection_id')->unique()->constrained('trading_engine_paper_position_projections')->restrictOnDelete();
            $table->unsignedBigInteger('lifecycle_version')->default(0);
            $table->unsignedBigInteger('next_observation_sequence')->default(1);
            $table->string('state', 32)->default('open')->index();
            $table->string('protection_state', 32)->default('none');
            $table->string('last_observation_id', 128)->nullable()->unique();
            $table->unsignedBigInteger('last_observation_sequence')->nullable();
            $table->string('last_market_cap_usd', 80)->nullable();
            $table->string('last_price_usd', 80)->nullable();
            $table->string('last_liquidity_usd', 80)->nullable();
            $table->string('observed_multiple', 80)->nullable();
            $table->string('peak_market_cap_usd', 80);
            $table->string('peak_multiple', 80)->default('1');
            $table->string('max_drawdown_percent', 80)->default('0');
            $table->string('last_event_id', 26)->nullable()->unique();
            $table->char('last_payload_sha256', 64)->nullable();
            $table->timestampTz('last_observed_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'next_observation_sequence'], 'te_paper_state_open_sequence_index');
        });

        Schema::create('trading_engine_paper_financial_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('position_state_id')->constrained('trading_engine_paper_position_states')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('observation_id', 128)->unique();
            $table->unsignedBigInteger('sequence');
            $table->string('idempotency_key', 128)->unique();
            $table->char('payload_sha256', 64);
            $table->json('payload');
            $table->string('status', 32)->index();
            $table->string('engine_operation_id', 26)->nullable()->unique();
            $table->string('engine_decision_id', 26)->nullable()->unique();
            $table->string('engine_event_id', 26)->nullable()->unique();
            $table->string('engine_settlement_id', 26)->nullable()->unique();
            $table->string('engine_decision', 16)->nullable();
            $table->string('last_error_code', 128)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('projected_at')->nullable();
            $table->timestamps();
            $table->unique(['position_state_id', 'sequence'], 'te_paper_financial_observation_sequence_unique');
        });

        Schema::create('trading_engine_paper_exit_settlement_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('position_projection_id')->unique()->constrained('trading_engine_paper_position_projections')->restrictOnDelete();
            $table->foreignId('position_state_id')->unique()->constrained('trading_engine_paper_position_states')->restrictOnDelete();
            $table->foreignId('wallet_projection_id')->constrained('trading_engine_paper_wallet_projections')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('engine_settlement_id', 26)->unique();
            $table->string('engine_decision_id', 26)->unique();
            $table->string('engine_order_id', 26)->unique();
            $table->string('engine_fill_id', 26)->unique();
            $table->string('engine_ledger_transaction_id', 26)->unique();
            $table->string('engine_event_id', 26)->unique();
            $table->string('cost_basis_native', 80);
            $table->string('proceeds_native', 80);
            $table->string('realized_pnl_native', 80);
            $table->string('exit_price_usd', 80);
            $table->string('exit_market_cap_usd', 80);
            $table->string('observed_multiple', 80);
            $table->string('fill_model', 64);
            $table->char('payload_sha256', 64);
            $table->timestampTz('settled_at');
            $table->timestampTz('projected_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_engine_paper_exit_settlement_projections');
        Schema::dropIfExists('trading_engine_paper_financial_observations');
        Schema::dropIfExists('trading_engine_paper_position_states');

        Schema::table('trading_engine_paper_wallet_projections', function (Blueprint $table): void {
            $table->dropColumn('realized_pnl_native');
        });
    }
};
