<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_engine_paper_entry_intents', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('recovery_count')->default(0);
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('submitting_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('acceptance_unknown_at')->nullable();
            $table->timestampTz('definitively_rejected_at')->nullable();
            $table->timestampTz('last_recovered_at')->nullable();
            $table->index(['status', 'submitting_at'], 'te_paper_entry_delivery_stale_index');
            $table->index(['status', 'last_recovered_at'], 'te_paper_entry_delivery_recovery_index');
        });

        Schema::table('trading_engine_paper_financial_observations', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('recovery_count')->default(0);
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('submitting_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('acceptance_unknown_at')->nullable();
            $table->timestampTz('definitively_rejected_at')->nullable();
            $table->timestampTz('last_recovered_at')->nullable();
            $table->index(['status', 'submitting_at'], 'te_paper_observation_delivery_stale_index');
            $table->index(['status', 'last_recovered_at'], 'te_paper_observation_delivery_recovery_index');
        });

        DB::table('trading_engine_paper_entry_intents')
            ->where('status', 'pending')
            ->update(['queued_at' => DB::raw('created_at')]);
        DB::table('trading_engine_paper_financial_observations')
            ->where('status', 'pending')
            ->update(['queued_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('trading_engine_paper_financial_observations', function (Blueprint $table): void {
            $table->dropIndex('te_paper_observation_delivery_stale_index');
            $table->dropIndex('te_paper_observation_delivery_recovery_index');
            $table->dropColumn([
                'attempt_count',
                'recovery_count',
                'queued_at',
                'submitting_at',
                'last_attempt_at',
                'acceptance_unknown_at',
                'definitively_rejected_at',
                'last_recovered_at',
            ]);
        });

        Schema::table('trading_engine_paper_entry_intents', function (Blueprint $table): void {
            $table->dropIndex('te_paper_entry_delivery_stale_index');
            $table->dropIndex('te_paper_entry_delivery_recovery_index');
            $table->dropColumn([
                'attempt_count',
                'recovery_count',
                'queued_at',
                'submitting_at',
                'last_attempt_at',
                'acceptance_unknown_at',
                'definitively_rejected_at',
                'last_recovered_at',
            ]);
        });
    }
};
