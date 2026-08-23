<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The dashboard map counts students per country for the active season. There
     * was an index on (season_id, school_id) but none on country, so the count
     * scanned the table — fine at 50k rows, not at the volume a full season
     * carries.
     */
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->index(['season_id', 'country_id']);
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropIndex(['season_id', 'country_id']);
        });
    }
};
