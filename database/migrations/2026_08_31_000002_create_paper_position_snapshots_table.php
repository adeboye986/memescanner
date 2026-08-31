<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('paper_position_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paper_position_id')
                ->constrained('paper_positions')
                ->cascadeOnDelete();

            $table->string('snapshot_type')->default('periodic')->index();
            $table->decimal('market_cap', 24, 8)->nullable();
            $table->decimal('price', 30, 18)->nullable();
            $table->decimal('liquidity', 24, 8)->nullable();

            $table->decimal('return_percent', 12, 4)->nullable();
            $table->decimal('multiple', 12, 4)->nullable();
            $table->decimal('drawdown_from_peak_percent', 12, 4)->nullable();

            $table->json('raw_data')->nullable();
            $table->timestamp('recorded_at')->index();
            $table->timestamps();

            $table->index([
                'paper_position_id',
                'snapshot_type',
                'recorded_at',
            ], 'paper_position_snapshot_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_position_snapshots');
    }
};
