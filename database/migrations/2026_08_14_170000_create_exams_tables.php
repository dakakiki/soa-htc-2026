<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An exam: an ordered selection of tests, tagged with an exam round
     * (Preliminary / National / …) and targeting difficulty levels (OD-3, pivot
     * instead of the legacy varchar(11) CSV). The legacy exam_type was always
     * empty (Sample lives in the round) and exam_password is an online-exam
     * field we drop.
     */
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->string('title', 1000);
            $table->text('description')->nullable();
            $table->foreignId('exam_round_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        // Difficulty levels an exam targets (OD-3, pivot instead of CSV).
        Schema::create('difficulty_level_exam', function (Blueprint $table) {
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('difficulty_level_id')->constrained()->cascadeOnDelete();
            $table->primary(['exam_id', 'difficulty_level_id']);
        });

        // Ordered tests pulled into an exam (legacy exam_tests.test_order -> position).
        Schema::create('exam_test', function (Blueprint $table) {
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->primary(['exam_id', 'test_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_test');
        Schema::dropIfExists('difficulty_level_exam');
        Schema::dropIfExists('exams');
    }
};
