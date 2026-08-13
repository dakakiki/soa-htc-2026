<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('year');
            // Part of the legacy competitor number (legacy el_settings.round_number).
            $table->unsignedInteger('round_number');
            $table->string('status', 20)->default('draft');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->unique('round_number');
            $table->index('status');
            $table->index('year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};
