<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records that a student session has cleared a competition quiz's password
     * (CC-06). Entering the password once unlocks the quiz for the life of the
     * session; the row dies with the session (cascade). Keeping this separate
     * leaves `student_sessions` itself quiz-agnostic (ADR-0013 / Slice 3b).
     */
    public function up(): void
    {
        Schema::create('student_session_quiz', function (Blueprint $table) {
            $table->foreignId('student_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->timestamp('unlocked_at');
            $table->primary(['student_session_id', 'quiz_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_session_quiz');
    }
};
