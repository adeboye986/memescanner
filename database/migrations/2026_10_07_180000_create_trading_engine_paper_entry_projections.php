<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_engine_paper_entry_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trade_opportunity_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('opportunity_link_id')->constrained('trading_engine_opportunity_links')->restrictOnDelete();
            $table->foreignId('opportunity_evaluation_id')->unique()->constrained('trading_engine_opportunity_evaluations')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 128)->unique();
            $table->char('payload_sha256', 64);
            $table->json('payload');
            $table->string('status', 32)->index();
            $table->string('engine_operation_id', 26)->nullable()->unique();
            $table->string('engine_wallet_id', 26)->nullable();
            $table->string('engine_intent_id', 26)->nullable()->unique();
            $table->string('engine_order_id', 26)->nullable()->unique();
            $table->string('engine_fill_id', 26)->nullable()->unique();
            $table->string('engine_position_id', 26)->nullable()->unique();
            $table->string('engine_event_id', 26)->nullable()->unique();
            $table->string('last_error_code', 128)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('projected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('trading_engine_paper_wallet_projections', function (Blueprint $table): void {
            $table->id();
            $table->string('engine_wallet_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('network_id', 64);
            $table->string('currency', 16);
            $table->string('opening_balance_native', 80);
            $table->string('available_balance_native', 80);
            $table->string('invested_balance_native', 80);
            $table->string('last_event_id', 26)->unique();
            $table->timestampTz('last_event_occurred_at');
            $table->char('last_payload_sha256', 64);
            $table->timestampTz('projected_at');
            $table->timestamps();
            $table->unique(['user_id', 'network_id', 'currency'], 'te_paper_wallet_user_network_currency_unique');
        });

        Schema::create('trading_engine_paper_position_projections', function (Blueprint $table): void {
            $table->id();
            $table->string('engine_position_id', 26)->unique();
            $table->foreignId('wallet_projection_id')->constrained('trading_engine_paper_wallet_projections')->restrictOnDelete();
            $table->foreignId('paper_entry_intent_id')->unique()->constrained('trading_engine_paper_entry_intents')->restrictOnDelete();
            $table->foreignId('trade_opportunity_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('opportunity_evaluation_id')->unique()->constrained('trading_engine_opportunity_evaluations')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('engine_intent_id', 26)->unique();
            $table->string('engine_order_id', 26)->unique();
            $table->string('engine_fill_id', 26)->unique();
            $table->string('engine_opportunity_id', 26);
            $table->string('network_id', 64);
            $table->string('asset_address', 128);
            $table->string('symbol', 64)->nullable();
            $table->string('state', 32)->index();
            $table->string('quantity', 80);
            $table->string('quantity_unit', 64);
            $table->string('cost_basis_native', 80);
            $table->string('entry_price_usd', 80);
            $table->string('entry_market_cap_usd', 80);
            $table->string('entry_liquidity_usd', 80)->nullable();
            $table->json('strategy_snapshot');
            $table->json('authority_snapshot');
            $table->string('entry_event_id', 26)->unique();
            $table->char('entry_payload_sha256', 64);
            $table->timestampTz('opened_at');
            $table->timestampTz('projected_at');
            $table->timestamps();
            $table->index(['user_id', 'state'], 'te_paper_position_user_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_engine_paper_position_projections');
        Schema::dropIfExists('trading_engine_paper_wallet_projections');
        Schema::dropIfExists('trading_engine_paper_entry_intents');
    }
};
