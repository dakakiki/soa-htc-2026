<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A quiz: the top of the content hierarchy — an ordered selection of exams,
     * typed (Sample / Competition) and targeting difficulty levels (OD-3, pivot
     * instead of the legacy varchar(11) CSV). Unlike tests/exams the legacy
     * quiz_password is populated (a bcrypt access code for taking the quiz), so
     * it is carried over as a nullable hash.
     */
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->string('title', 1000);
            $table->text('description')->nullable();
            $table->string('quiz_type', 20)->default('competition');
            $table->string('quiz_password')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        // Difficulty levels a quiz targets (OD-3, pivot instead of CSV).
        Schema::create('difficulty_level_quiz', function (Blueprint $table) {
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignId('difficulty_level_id')->constrained()->cascadeOnDelete();
            $table->primary(['quiz_id', 'difficulty_level_id']);
        });

        // Ordered exams pulled into a quiz (legacy quiz_exams.exam_order -> position).
        Schema::create('exam_quiz', function (Blueprint $table) {
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->primary(['quiz_id', 'exam_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_quiz');
        Schema::dropIfExists('difficulty_level_quiz');
        Schema::dropIfExists('quizzes');
    }
};
