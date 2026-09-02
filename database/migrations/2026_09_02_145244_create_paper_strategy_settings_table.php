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
        Schema::create('paper_strategy_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('stop_loss_percent', 8, 4)->default(10);
            $table->decimal('protection_level_1_percent', 8, 4)->default(100);
            $table->decimal('protection_level_2_percent', 8, 4)->default(200);
            $table->timestamps();
        });

        DB::table('paper_strategy_settings')->insert([
            'name' => 'default',
            'stop_loss_percent' => 10,
            'protection_level_1_percent' => 100,
            'protection_level_2_percent' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paper_strategy_settings');
    }
};
