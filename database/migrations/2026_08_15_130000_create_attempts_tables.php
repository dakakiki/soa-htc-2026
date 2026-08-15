<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A competitor's single attempt at a test (ADR-0016: one attempt per
     * registration+test, no retake). The attempt belongs to the REGISTRATION, not
     * the 180-minute web session, since it outlives the session. The server owns
     * the clock: `expires_at` = `started_at` + the test duration. Answers are
     * written once, atomically, at submit — never during the run (§6.1).
     */
    public function up(): void
    {
        Schema::create('attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('in_progress');
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();
            $table->string('channel', 20)->default('web');
            $table->timestamps();

            // One attempt per test per registration (ADR-0016).
            $table->unique(['registration_id', 'test_id']);
        });

        // The submitted answers, one row per question. Response shape depends on
        // the question type (multiple choice / gap-filling / essay); it is stored
        // raw and NOT graded here — grading and publication are Faza 5 (ADR-0013).
        Schema::create('attempt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->json('response')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_answers');
        Schema::dropIfExists('attempts');
    }
};
