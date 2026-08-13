<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_countries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_user_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['season_user_assignment_id', 'country_id'], 'assignment_country_unique');
            $table->index('country_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_countries');
    }
};
