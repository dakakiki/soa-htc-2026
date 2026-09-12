<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which round is being run is not a fact about the round (ADR-0077 reverses
 * ADR-0057).
 *
 * The client's rounds do not advance together. In the legacy database right now
 * 47 countries sit on National round and 49 on Regional Qualifiers, and legacy
 * carries that where it belongs — `el_country.round`, one per country. A single
 * global flag cannot describe it: whatever it said was wrong for about half the
 * world, and it said it on the public front page.
 *
 * The owner's decision (2026-09-12) is not to move the flag to the country but
 * to drop it: nobody should have to keep ninety-odd countries' rounds up to
 * date for a strip of text. Nothing needed it to work — Publishing used it to
 * preselect one exam, and does without.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 🪤 The index goes first and in its own statement. MySQL drops a
        // single-column index along with the column, but SQLite rebuilds the
        // table and then refuses it — "error in index exam_rounds_is_current_index
        // after drop column" — so a run that was green on MySQL took the whole
        // suite down on SQLite.
        Schema::table('exam_rounds', function (Blueprint $table) {
            $table->dropIndex('exam_rounds_is_current_index');
        });

        Schema::table('exam_rounds', function (Blueprint $table) {
            $table->dropColumn('is_current');
        });
    }

    public function down(): void
    {
        Schema::table('exam_rounds', function (Blueprint $table) {
            $table->boolean('is_current')->default(false)->after('sort_order');
            $table->index('is_current');
        });
    }
};
