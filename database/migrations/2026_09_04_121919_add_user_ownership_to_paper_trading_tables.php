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
        Schema::table('trade_opportunities', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('discovery_key', 64)->nullable()->after('scanner');
            $table->index(['user_id', 'status', 'qualified_at']);
            $table->unique(['user_id', 'discovery_key']);
        });
        Schema::table('paper_positions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index(['user_id', 'status']);
        });
        Schema::table('paper_wallets', function (Blueprint $table) {
            $table->dropUnique(['name', 'chain']);
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->unique(['user_id', 'name', 'chain']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_opportunities', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'discovery_key']);
            $table->dropIndex(['user_id', 'status', 'qualified_at']);
            $table->dropColumn('discovery_key');
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('paper_positions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('paper_wallets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'name', 'chain']);
            $table->dropConstrainedForeignId('user_id');
            $table->unique(['name', 'chain']);
        });
    }
};
