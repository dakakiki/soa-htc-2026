<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every attempt ever made was filed as a contest one: the stamp compared a
     * cast enum against the string behind it and so was always false. The code
     * is fixed, but the rows are not, and the one-attempt rule reads the rows —
     * without this, a second go at any sample test already sat is still refused.
     */
    public function up(): void
    {
        DB::table('attempts')
            ->whereIn('quiz_id', DB::table('quizzes')->where('quiz_type', 'sample')->select('id'))
            ->update(['is_practice' => true]);
    }

    /**
     * Not reversed. The value written here is the one the rows should always
     * have carried; putting the wrong one back would only restore the bug.
     */
    public function down(): void {}
};
