<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trade_opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('chain')->index();
            $table->string('address');
            $table->string('symbol')->nullable();
            $table->string('name')->nullable();
            $table->string('scanner')->index();
            $table->string('status')->index();
            $table->string('execution_mode');
            $table->string('entry_mode');
            $table->string('pair_address')->nullable();
            $table->decimal('price', 30, 18)->nullable();
            $table->decimal('market_cap', 20, 4)->nullable();
            $table->decimal('liquidity', 20, 4)->nullable();
            $table->decimal('volume', 20, 4)->nullable();
            $table->json('qualification_data')->nullable();
            $table->json('security_data')->nullable();
            $table->json('execution_data')->nullable();
            $table->foreignId('paper_position_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('qualified_at');
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['chain', 'address', 'scanner']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trade_opportunities');
    }
};
