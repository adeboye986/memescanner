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
        Schema::create('trading_engine_opportunity_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_opportunity_id')->unique()->constrained()->restrictOnDelete();
            $table->char('engine_opportunity_id', 26)->unique();
            $table->char('recorded_event_id', 26)->unique();
            $table->unsignedBigInteger('user_id');
            $table->char('discovery_key', 64);
            $table->string('scanner', 32);
            $table->string('network_id', 64);
            $table->string('asset_address', 128);
            $table->timestampTz('recorded_at');
            $table->timestampTz('linked_at');

            $table->foreign('recorded_event_id', 'te_opp_link_recorded_event_fk')
                ->references('event_id')
                ->on('trading_engine_event_inbox')
                ->restrictOnDelete();
            $table->index(['user_id', 'discovery_key'], 'te_opp_link_user_discovery_idx');
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trading_engine_opportunity_links_no_update
                BEFORE UPDATE ON trading_engine_opportunity_links
                BEGIN
                    SELECT RAISE(ABORT, 'trading engine opportunity links are append-only');
                END
                SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER trading_engine_opportunity_links_no_delete
                BEFORE DELETE ON trading_engine_opportunity_links
                BEGIN
                    SELECT RAISE(ABORT, 'trading engine opportunity links are append-only');
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
            DB::statement('DROP TRIGGER IF EXISTS trading_engine_opportunity_links_no_update');
            DB::statement('DROP TRIGGER IF EXISTS trading_engine_opportunity_links_no_delete');
        }

        Schema::dropIfExists('trading_engine_opportunity_links');
    }
};
