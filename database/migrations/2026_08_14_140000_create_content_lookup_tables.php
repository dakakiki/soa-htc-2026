<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Content configuration lookups for the assessment hierarchy: test types
     * (Reading/Writing/…), exam rounds (Preliminary/National/…) and question
     * tags. Reference data that carries across seasons.
     */
    public function up(): void
    {
        Schema::create('test_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('exam_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('question_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_tags');
        Schema::dropIfExists('exam_rounds');
        Schema::dropIfExists('test_types');
    }
};
