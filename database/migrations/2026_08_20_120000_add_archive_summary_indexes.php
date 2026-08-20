<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Composite indexes for the archive summary (ArchiveController). Every read is a
     * `WHERE round_number = ?` aggregate grouped by a denormalized column, so a
     * `(round_number, <group column>)` index turns each GROUP BY into an index scan
     * instead of a full table scan + filesort. For the "participated" figures — a
     * `count(distinct competitor_number)` per group — the competitor_number is folded
     * into the index too, so the whole count is answered index-only, no temp table.
     *
     * Measured on round 13 (93k roster rows / 91k result rows): the summary's DB time
     * dropped from ~5.0 s to well under a second. The archive is written rarely
     * (season reset + the legacy import) and read often, so extra indexes are cheap.
     */
    public function up(): void
    {
        Schema::table('archive_registrations', function (Blueprint $table) {
            $table->index(['round_number', 'country'], 'ar_round_country');
            $table->index(['round_number', 'region'], 'ar_round_region');
            $table->index(['round_number', 'venue'], 'ar_round_venue');
            $table->index(['round_number', 'level'], 'ar_round_level');
            $table->index(['round_number', 'grade'], 'ar_round_grade');
        });

        Schema::table('archive_registration_results', function (Blueprint $table) {
            // Total participated: count(distinct competitor_number) for the round.
            $table->index(['round_number', 'competitor_number'], 'arr_round_comp');
            // Participated per country / region / venue — covering, so the distinct
            // count never touches the table.
            $table->index(['round_number', 'country', 'competitor_number'], 'arr_round_country_comp');
            $table->index(['round_number', 'region', 'competitor_number'], 'arr_round_region_comp');
            $table->index(['round_number', 'venue', 'competitor_number'], 'arr_round_venue_comp');
        });
    }

    public function down(): void
    {
        Schema::table('archive_registrations', function (Blueprint $table) {
            $table->dropIndex('ar_round_country');
            $table->dropIndex('ar_round_region');
            $table->dropIndex('ar_round_venue');
            $table->dropIndex('ar_round_level');
            $table->dropIndex('ar_round_grade');
        });

        Schema::table('archive_registration_results', function (Blueprint $table) {
            $table->dropIndex('arr_round_comp');
            $table->dropIndex('arr_round_country_comp');
            $table->dropIndex('arr_round_region_comp');
            $table->dropIndex('arr_round_venue_comp');
        });
    }
};
