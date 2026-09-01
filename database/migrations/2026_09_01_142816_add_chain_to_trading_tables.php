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
        Schema::table('paper_positions', function (Blueprint $table) {
            $table->string('chain')->default('solana')->after('id')->index();
            $table->index(['chain', 'address', 'status']);
        });

        if (Schema::hasTable('token_scans')) {
            Schema::table('token_scans', function (Blueprint $table) {
                $table->dropUnique(['address']);
                $table->string('chain')->default('solana')->after('id')->index();
                $table->unique(['chain', 'address']);
            });
        }

        Schema::table('system_activities', function (Blueprint $table) {
            $table->string('chain')->nullable()->after('action')->index();
            $table->index(['action', 'chain', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_activities', function (Blueprint $table) {
            $table->dropIndex(['action', 'chain', 'status']);
            $table->dropColumn('chain');
        });

        if (Schema::hasTable('token_scans')) {
            Schema::table('token_scans', function (Blueprint $table) {
                $table->dropUnique(['chain', 'address']);
                $table->dropColumn('chain');
                $table->unique('address');
            });
        }

        Schema::table('paper_positions', function (Blueprint $table) {
            $table->dropIndex(['chain', 'address', 'status']);
            $table->dropColumn('chain');
        });
    }
};
