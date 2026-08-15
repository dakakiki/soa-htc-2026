<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A seasonal competitor registration: one person entered into one season,
     * school, country and difficulty level, identified publicly by a
     * competitor_number (round_number + zero-padded per-season sequence, e.g.
     * "14000001"). The internal key is `id`; competitor_number is only the
     * public/form identifier.
     *
     * Indexed for the hot paths this feeds later: web identification looks up by
     * competitor_number (unique), and coordinator listings scope by school.
     */
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->string('competitor_number', 20);
            $table->unsignedInteger('sequence');
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            // Free-text home school, for a competitor sitting at a different venue.
            $table->string('school_external', 200)->nullable();
            $table->foreignId('country_id')->constrained();
            $table->foreignId('difficulty_level_id')->constrained();
            $table->string('name', 250);
            $table->date('date_of_birth')->nullable();
            $table->unsignedTinyInteger('grade')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();

            // Public identifier: globally unique (round prefix makes it unique
            // across seasons) and the lookup key for web identification.
            $table->unique('competitor_number');
            // Per-season running number — guarantees generation never collides.
            $table->unique(['season_id', 'sequence']);
            // Coordinator listings are scoped by school within a season.
            $table->index(['season_id', 'school_id']);
            $table->index('status');
            $table->index('legacy_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
