<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turnout on the dashboard is "how many competitors submitted at least one
 * test, and how many have a published mark" — one row per competitor, out of
 * 184,386 attempts. Grouping attempts by registration first and reading only
 * the two timestamps makes that an index-only scan.
 *
 * Measured on the r14 roster: the pair of country queries behind the map took
 * 3,461 ms as two `count(distinct)` passes, 1,851 ms once folded into one
 * pre-aggregated query, and **916 ms** with this index. The whole dashboard was
 * 5.7 s.
 *
 * 🪤 The index only pays for the pre-aggregated shape. The same measurement
 * against the `count(distinct case when …)` form moved 2,224 → 1,881 ms, which
 * is why the query was rewritten rather than just indexed.
 *
 * The second index is for the headline counts, which read one row per
 * registration and no more: roster size, present, absent, distinct countries
 * and missing dates of birth. Covering them turns that scan index-only too —
 * 618 → 283 ms over the same roster.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            $table->index(['registration_id', 'submitted_at', 'published_at'], 'attempts_turnout_index');
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->index(['season_id', 'attendance', 'country_id', 'date_of_birth'], 'registrations_headline_index');
        });
    }

    public function down(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            $table->dropIndex('attempts_turnout_index');
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->dropIndex('registrations_headline_index');
        });
    }
};
