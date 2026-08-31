<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('paper_positions', function (Blueprint $table) {
            $table->id();
            $table->string('address')->index();
            $table->string('symbol')->nullable();
            $table->string('name')->nullable();

            $table->decimal('discovery_market_cap', 24, 8)->nullable();
            $table->decimal('entry_market_cap', 24, 8);
            $table->decimal('entry_price', 30, 18)->nullable();
            $table->decimal('entry_liquidity', 24, 8)->nullable();
            $table->decimal('move_since_discovery_percent', 12, 4)->nullable();

            $table->decimal('last_market_cap', 24, 8)->nullable();
            $table->decimal('last_price', 30, 18)->nullable();
            $table->timestamp('last_checked_at')->nullable();

            $table->decimal('peak_market_cap', 24, 8)->nullable();
            $table->decimal('peak_multiple', 12, 4)->default(1);
            $table->decimal('max_drawdown_percent', 12, 4)->default(0);

            $table->json('milestones')->nullable();
            $table->json('meta')->nullable();

            $table->string('status')->default('open')->index();
            $table->timestamp('entry_at');
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'last_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_positions');
    }
};
