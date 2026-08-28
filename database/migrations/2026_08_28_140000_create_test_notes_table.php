<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A note the author drops between the questions of a test.
 *
 * Until now the only way to put a task heading in front of a group of questions
 * was to enter it AS a question — a title with nothing under it. The legacy
 * import brought twenty of those across (ADR-0060). A note is not a question:
 * it is never answered, never graded, never numbered, and the test's
 * completeness rule does not count it (owner, 2026-08-28).
 *
 * 🪤 Anchored BEFORE a question rather than placed in the same order as one.
 * The exam screen numbers questions by their index in the list it is given
 * (ADR-0034: the number comes from the question's place, not its title), so a
 * note sharing that sequence would eat a number. `before_position` is therefore
 * "how many questions come first": 0 puts the note above everything, and a note
 * equal to the number of questions falls after the last one. `sort_order`
 * separates two notes anchored at the same place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('before_position')->default(0);
            $table->unsignedInteger('sort_order')->default(1);
            $table->text('body');
            $table->timestamps();

            $table->index(['test_id', 'before_position', 'sort_order'], 'test_notes_place_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_notes');
    }
};
