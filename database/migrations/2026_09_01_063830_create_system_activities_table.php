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
        Schema::create('system_activities', function (Blueprint $table) {
            $table->id();
            $table->string('action')->index();
            $table->string('command');
            $table->string('label');
            $table->string('status')->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->integer('exit_code')->nullable();
            $table->longText('output')->nullable();
            $table->text('error_message')->nullable();
            $table->string('triggered_by')->default('manual')->index();
            $table->timestamps();

            $table->index(['action', 'triggered_by', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_activities');
    }
};
