<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grading outputs (Faza 5, ADR-0019). Auto-gradable questions (multiple
     * choice, gap-filling) are scored the moment an attempt completes; essays
     * leave the attempt `pending_grading` until an admin grades them. Raw answers
     * stay in `attempt_answers`; here we add the derived per-answer correctness
     * and points, plus the attempt totals.
     */
    public function up(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            $table->decimal('score', 8, 2)->nullable()->after('status');
            $table->decimal('max_score', 8, 2)->nullable()->after('score');
            $table->string('grading_status', 20)->nullable()->after('max_score');
        });

        Schema::table('attempt_answers', function (Blueprint $table) {
            $table->boolean('is_correct')->nullable()->after('response');
            $table->decimal('awarded_points', 8, 2)->nullable()->after('is_correct');
        });
    }

    public function down(): void
    {
        Schema::table('attempt_answers', function (Blueprint $table) {
            $table->dropColumn(['is_correct', 'awarded_points']);
        });

        Schema::table('attempts', function (Blueprint $table) {
            $table->dropColumn(['score', 'max_score', 'grading_status']);
        });
    }
};
