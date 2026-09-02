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
        Schema::create('trade_opportunity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_opportunity_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['trade_opportunity_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trade_opportunity_events');
    }
};
