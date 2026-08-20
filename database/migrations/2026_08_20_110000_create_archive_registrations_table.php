<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The roster archive (Layer C, ADR-0027 / OD-9). `season:reset` snapshots the
     * whole registration roster here before wiping — every registered competitor,
     * not just those with published results — so a past season's "registered per
     * country vs. actually participated" is still answerable (registered = rows
     * here; participated = distinct competitors in `archive_registration_results`).
     * Fully denormalized and self-contained, like the results archive: it carries
     * the labels and never joins back to config to be read. Also the target for the
     * historical migration (every legacy `el_student` row lands here).
     */
    public function up(): void
    {
        Schema::create('archive_registrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('season_id');
            $table->unsignedInteger('round_number');
            $table->string('competitor_number', 20);
            $table->string('name', 250);
            $table->string('country')->nullable();
            $table->string('region')->nullable();
            $table->string('venue')->nullable();
            $table->string('school_external')->nullable();
            $table->string('level')->nullable();
            $table->unsignedTinyInteger('grade')->nullable();
            $table->string('attendance', 16)->nullable();
            $table->timestamp('archived_at');

            $table->index('season_id');
            $table->index('round_number');
            $table->index('competitor_number');
            $table->index('country'); // per-country registered counts
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_registrations');
    }
};
