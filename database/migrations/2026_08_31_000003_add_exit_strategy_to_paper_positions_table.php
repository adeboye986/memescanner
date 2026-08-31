<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('paper_positions', function (Blueprint $table) {
            $table->decimal(
                'remaining_fraction',
                10,
                6
            )->default(1);

            $table->decimal(
                'realized_value_multiple',
                16,
                8
            )->default(0);

            $table->decimal(
                'strategy_value_multiple',
                16,
                8
            )->default(1);

            $table->decimal(
                'strategy_return_percent',
                16,
                6
            )->default(0);

            $table->boolean('tp_50_hit')->default(false);
            $table->boolean('tp_2x_hit')->default(false);
            $table->boolean('stop_loss_hit')->default(false);
            $table->boolean('trailing_stop_hit')->default(false);

            $table->json('exit_events')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('paper_positions', function (Blueprint $table) {
            $table->dropColumn([
                'remaining_fraction',
                'realized_value_multiple',
                'strategy_value_multiple',
                'strategy_return_percent',
                'tp_50_hit',
                'tp_2x_hit',
                'stop_loss_hit',
                'trailing_stop_hit',
                'exit_events',
            ]);
        });
    }
};
