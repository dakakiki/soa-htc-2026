<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The reusable question bank: a question with its answer options and the
     * difficulty levels it targets (OD-3: content maps to levels via a pivot,
     * replacing the legacy varchar(11) CSV). Links to tests come later via a
     * separate pivot when the Tests layer is built.
     */
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->string('title', 3000);
            $table->text('description')->nullable();
            $table->string('question_type', 30)->default('multiple_choice');
            $table->decimal('points', 5, 2)->default(1);
            $table->foreignId('question_tag_id')->nullable()->constrained()->nullOnDelete();
            $table->string('image_path')->nullable();
            $table->string('audio_path')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('question_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        // Difficulty levels a question targets (OD-3, pivot instead of CSV).
        Schema::create('difficulty_level_question', function (Blueprint $table) {
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('difficulty_level_id')->constrained()->cascadeOnDelete();
            $table->primary(['question_id', 'difficulty_level_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('difficulty_level_question');
        Schema::dropIfExists('question_answers');
        Schema::dropIfExists('questions');
    }
};
