<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Layout blocks (ADR-0043): the ordered sections a zone is built from.
 *
 * A zone is declared in code (`LayoutZones`), never created here — the legacy
 * app let an admin invent a position by typing its name, and a position with no
 * matching slot in the template rendered nowhere with nothing to report it.
 *
 * Fields differ per block type, so they live in one validated `data` payload
 * rather than in a column per need. That is what the legacy `modules` table did
 * the other way round, growing a `back_color`, a `back_image` and a
 * `file_upload` that most rows never used and nothing could type-check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_layout_blocks', function (Blueprint $table) {
            $table->id();

            // Both come from code-side registries; stored as strings so a
            // retired type keeps its row readable instead of failing to load.
            $table->string('zone', 64);
            $table->string('type', 32);

            $table->unsignedInteger('position')->default(0);
            // Whether the admin allows this section at all. Season rules are a
            // separate, independent gate applied when the page is served.
            $table->boolean('status')->default(true);

            $table->json('data')->nullable();

            // Images are references to the library, as everywhere else in the
            // CMS: deleting the file clears the reference instead of leaving a
            // broken <img>, and deleting the block never touches the file.
            $table->foreignId('image_media_id')->nullable()
                ->constrained('cms_media')->nullOnDelete();

            $table->timestamps();

            $table->index(['zone', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_layout_blocks');
    }
};
