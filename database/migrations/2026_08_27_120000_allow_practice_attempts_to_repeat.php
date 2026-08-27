<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Practice may be repeated without limit (owner, 2026-08-27).
 *
 * ADR-0016 — one attempt per test — is a CONTEST rule, and ADR-0022 made it a
 * hard database guarantee: `active_test_id` is the test id unless the attempt is
 * void, and `unique(registration_id, active_test_id)` does the rest. That is
 * exactly right for the contest, where a second run would be a second chance
 * nobody else got, and exactly wrong for a sample test, whose whole point is
 * repetition.
 *
 * So practice attempts step out of the unique instead of the rule being relaxed
 * for everyone: `is_practice` is stamped when the attempt is created, and the
 * generated column returns NULL for those rows the same way it does for voided
 * ones. The competition keeps its guarantee, unchanged and still enforced by the
 * database rather than by whoever remembers to check.
 *
 * 🪤 The column stays VIRTUAL. A STORED generated column forces a table copy,
 * which MySQL refuses on a table carrying foreign keys (err 1215).
 */
return new class extends Migration
{
    public function up(): void
    {
        // The unique has `registration_id` leftmost, so on MySQL it can be the
        // index backing that foreign key. Give the key its own index before
        // taking the unique away, or dropping it is refused.
        Schema::table('attempts', function (Blueprint $table) {
            $table->index('registration_id', 'attempts_registration_id_index');
        });

        Schema::table('attempts', function (Blueprint $table) {
            $table->dropUnique(['registration_id', 'active_test_id']);
        });

        Schema::table('attempts', function (Blueprint $table) {
            $table->dropColumn('active_test_id');
        });

        Schema::table('attempts', function (Blueprint $table) {
            $table->boolean('is_practice')->default(false)->after('quiz_id');
        });

        Schema::table('attempts', function (Blueprint $table) {
            $table->unsignedBigInteger('active_test_id')
                ->virtualAs("case when status = 'void' or is_practice = 1 then null else test_id end")
                ->nullable()
                ->after('test_id');

            $table->unique(['registration_id', 'active_test_id']);
        });
    }

    public function down(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            $table->dropUnique(['registration_id', 'active_test_id']);
        });

        Schema::table('attempts', function (Blueprint $table) {
            $table->dropColumn(['active_test_id', 'is_practice']);
        });

        Schema::table('attempts', function (Blueprint $table) {
            $table->unsignedBigInteger('active_test_id')
                ->virtualAs("case when status = 'void' then null else test_id end")
                ->nullable()
                ->after('test_id');

            $table->unique(['registration_id', 'active_test_id']);
        });

        Schema::table('attempts', function (Blueprint $table) {
            $table->dropIndex('attempts_registration_id_index');
        });
    }
};
