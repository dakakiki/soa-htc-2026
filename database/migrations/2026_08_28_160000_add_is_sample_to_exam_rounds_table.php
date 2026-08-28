<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which round is practice, said once instead of guessed from its name.
 *
 * Three places in the domain asked `exam_rounds.name = 'Sample'`:
 * `AttemptGrader` (a practice result publishes itself the moment it is graded),
 * `RegistrationResults` (practice is never a column in the results grid) and
 * `ResultLedger` (a practice attempt is never written to Layer B). All three of
 * them therefore turned on a label an administrator can retype, and nothing
 * stopped them: rename the round and practice quietly walks into the official
 * results while its own auto-publishing stops.
 *
 * 🪤 Not the same thing as `quizzes.quiz_type = sample`. That is what a
 * competitor enters — a practice quiz, open all year. This is where an exam sits
 * in the competition path. The code has always kept them apart and must keep
 * doing so.
 *
 * Nothing here is administrator-editable, on purpose: this is structure, not
 * wording. `ContentLookupSeeder` sets it on a fresh install; this backfills the
 * database that already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_rounds', function (Blueprint $table) {
            $table->boolean('is_sample')->default(false)->after('is_current');
            $table->index('is_sample');
        });

        // `legacy_id` 5 is what the seeder keys the round on and what the legacy
        // import carried across, so it survives a rename; the name is the
        // fallback for a row that never came from either.
        $marked = DB::table('exam_rounds')->where('legacy_id', 5)->update(['is_sample' => true]);

        if ($marked === 0) {
            DB::table('exam_rounds')->where('name', 'Sample')->update(['is_sample' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('exam_rounds', function (Blueprint $table) {
            $table->dropIndex(['is_sample']);
            $table->dropColumn('is_sample');
        });
    }
};
