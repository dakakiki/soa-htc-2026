<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_schools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_user_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['season_user_assignment_id', 'school_id'], 'assignment_school_unique');
            $table->index('school_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_schools');
    }
};
