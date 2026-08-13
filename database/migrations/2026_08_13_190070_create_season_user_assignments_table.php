<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_user_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 40);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            // A user holds a given role at most once per season.
            $table->unique(['season_id', 'user_id', 'role']);
            $table->index(['season_id', 'role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_user_assignments');
    }
};
