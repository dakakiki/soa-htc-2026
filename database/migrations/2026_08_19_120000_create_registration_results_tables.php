<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The results layer (Layer B, ADR-0027). `registration_results` holds one
     * denormalized row per (registration, test): the published score plus the
     * exam round / test type / quiz / season it belongs to, so the grid, reports
     * and the "all" export read a single flat table instead of joining `attempts`
     * live. Rows arrive from two sources: publishing an in-app attempt
     * (`source=attempt`, synced by ResultLedger) or importing offline results
     * (`source=import`). `attempts`/`attempt_answers` (Layer A) remain the record
     * of what a competitor actually did; this is the record of the official result.
     *
     * The Sample (practice) round never lands here — it is auto-published and
     * lives only in `attempts` (RegistrationResults already excludes it).
     *
     * `registration_qualifications` carries the S/Q/F advancement code per
     * (registration, exam round) that fills the Regional Qualifiers / World final
     * grid columns (legacy `q_semi`/`q_quali`/`q_final`).
     */
    public function up(): void
    {
        Schema::create('registration_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            // Denormalized from the test's active exam (round + type), the attempt's
            // quiz, and the registration's season — so no live JOINs are needed to read.
            $table->foreignId('exam_round_id')->constrained();
            $table->foreignId('test_type_id')->nullable()->constrained();
            $table->foreignId('quiz_id')->nullable()->constrained();
            $table->foreignId('season_id')->constrained();
            $table->decimal('score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->string('source', 20)->default('attempt'); // attempt | import
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One official result per test per registration (mirrors attempts, ADR-0016).
            $table->unique(['registration_id', 'test_id']);
            $table->index(['registration_id', 'exam_round_id']);
            $table->index('season_id');
        });

        Schema::create('registration_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_round_id')->constrained();
            $table->foreignId('season_id')->constrained();
            $table->string('code', 1);                          // S | Q | F (advancement)
            $table->string('source', 20)->default('import');   // import | attempt
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['registration_id', 'exam_round_id']);
            $table->index('season_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_qualifications');
        Schema::dropIfExists('registration_results');
    }
};
