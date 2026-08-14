<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A test: an ordered selection of questions from the bank, typed (Reading /
     * Writing / Use of English) and targeting difficulty levels (OD-3, pivot
     * instead of the legacy varchar(11) CSV). Round belongs to the Exams layer,
     * not here (legacy tests.round was always empty); the legacy test_index /
     * test_password online-exam fields are dropped.
     */
    public function up(): void
    {
        Schema::create('tests', function (Blueprint $table) {
            $table->id();
            $table->string('title', 1000);
            $table->text('description')->nullable();
            $table->foreignId('test_type_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('duration')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        // Difficulty levels a test targets (OD-3, pivot instead of CSV).
        Schema::create('difficulty_level_test', function (Blueprint $table) {
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            $table->foreignId('difficulty_level_id')->constrained()->cascadeOnDelete();
            $table->primary(['test_id', 'difficulty_level_id']);
        });

        // Ordered questions pulled from the bank into a test (legacy
        // test_questions_for_test.question_order -> position).
        Schema::create('question_test', function (Blueprint $table) {
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->primary(['test_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_test');
        Schema::dropIfExists('difficulty_level_test');
        Schema::dropIfExists('tests');
    }
};
