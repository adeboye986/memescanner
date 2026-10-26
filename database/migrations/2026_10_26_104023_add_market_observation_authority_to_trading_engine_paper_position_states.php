<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_engine_paper_position_states', function (Blueprint $table): void {
            $table->string('market_observation_authority', 32)
                ->default('laravel')
                ->index('te_paper_state_observation_authority_index');
        });
    }

    public function down(): void
    {
        Schema::table('trading_engine_paper_position_states', function (Blueprint $table): void {
            $table->dropIndex('te_paper_state_observation_authority_index');
            $table->dropColumn('market_observation_authority');
        });
    }
};
