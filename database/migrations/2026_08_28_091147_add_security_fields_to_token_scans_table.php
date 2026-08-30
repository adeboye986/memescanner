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
        Schema::table('token_scans', function (Blueprint $table) {
            $table->unsignedInteger('security_score')->nullable();
            $table->boolean('security_passed')->nullable();
            $table->json('security_risks')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('token_scans', function (Blueprint $table) {
            $table->dropColumn([
                'security_score',
                'security_passed',
                'security_risks',
            ]);
        });
    }
};
