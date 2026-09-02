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
        Schema::create('application_settings', function (Blueprint $table) {
            $table->id();
            $table->string('scope')->default('system');
            $table->unsignedBigInteger('owner_id')->default(0);
            $table->string('group');
            $table->string('key');
            $table->string('type');
            $table->longText('value')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->timestamps();

            $table->unique(['scope', 'owner_id', 'group', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_settings');
    }
};
