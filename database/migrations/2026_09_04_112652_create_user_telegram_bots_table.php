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
        Schema::create('user_telegram_bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('public_id', 48)->unique();
            $table->text('bot_token');
            $table->string('bot_username');
            $table->text('webhook_secret');
            $table->string('telegram_bot_id')->unique();
            $table->string('display_name')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->timestamp('webhook_configured_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_telegram_bots');
    }
};
