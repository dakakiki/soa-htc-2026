<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('difficulty_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('difficulty_category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('legacy_id')->nullable()->index();
            $table->timestamps();

            $table->index('difficulty_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('difficulty_levels');
    }
};
