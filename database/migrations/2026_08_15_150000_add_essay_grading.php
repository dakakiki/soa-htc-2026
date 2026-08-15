<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manual essay grading (Faza 5 Slice 5b, CC-09). An admin awards points and a
     * note per essay answer, recording who graded and when. A correction keeps the
     * previous value and a required reason in `grade_revisions` (audit); nothing is
     * overwritten silently.
     */
    public function up(): void
    {
        Schema::table('attempt_answers', function (Blueprint $table) {
            $table->foreignId('graded_by')->nullable()->after('awarded_points')->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable()->after('graded_by');
            $table->text('grade_note')->nullable()->after('graded_at');
        });

        Schema::create('grade_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_answer_id')->constrained()->cascadeOnDelete();
            $table->decimal('previous_points', 8, 2)->nullable();
            $table->text('previous_note')->nullable();
            $table->text('reason');
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('attempt_answer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_revisions');

        Schema::table('attempt_answers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('graded_by');
            $table->dropColumn(['graded_at', 'grade_note']);
        });
    }
};
