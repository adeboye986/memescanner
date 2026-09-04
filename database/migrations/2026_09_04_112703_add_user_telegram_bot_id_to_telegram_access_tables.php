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
        Schema::table('telegram_identities', function (Blueprint $table) {
            $table->foreignId('user_telegram_bot_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });
        Schema::table('telegram_link_tokens', function (Blueprint $table) {
            $table->foreignId('user_telegram_bot_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telegram_identities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_telegram_bot_id');
        });
        Schema::table('telegram_link_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_telegram_bot_id');
        });
    }
};
