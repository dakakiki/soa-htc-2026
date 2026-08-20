<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The results archive (Layer C, ADR-0027). Before `season:reset` wipes the
     * season-transactional data, it snapshots the results layer (Layer B) here,
     * tagged with the season and its `round_number`. Because the registrations —
     * and their country/venue/level — are wiped, and content may be re-versioned,
     * the archive is fully denormalized and self-contained: it carries the
     * human-readable labels and never joins back to config to be read. One row per
     * archived (competitor, test) result, plus the S/Q/F advancement codes.
     */
    public function up(): void
    {
        Schema::create('archive_registration_results', function (Blueprint $table) {
            $table->id();
            // Season tags (plain ids — an archive constrains nothing).
            $table->unsignedBigInteger('season_id');
            $table->unsignedInteger('round_number');
            // Competitor identity, snapshotted (registrations are wiped).
            $table->string('competitor_number', 20);
            $table->string('student_name', 250);
            $table->string('country')->nullable();
            $table->string('region')->nullable();
            $table->string('venue')->nullable();
            $table->string('school_external')->nullable();
            $table->string('level')->nullable();
            // Content labels, snapshotted (content may be re-versioned later).
            $table->string('exam_round');
            $table->string('test_type')->nullable();
            $table->string('quiz')->nullable();
            $table->string('test')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->string('source', 20);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at');

            $table->index('season_id');
            $table->index('round_number');
            $table->index('competitor_number');
        });

        Schema::create('archive_registration_qualifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('season_id');
            $table->unsignedInteger('round_number');
            $table->string('competitor_number', 20);
            $table->string('student_name', 250);
            $table->string('exam_round');
            $table->string('code', 1);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at');

            $table->index('season_id');
            $table->index('round_number');
            $table->index('competitor_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_registration_qualifications');
        Schema::dropIfExists('archive_registration_results');
    }
};
