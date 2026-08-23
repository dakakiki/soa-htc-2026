<?php

use App\Domain\Cms\Models\Media;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The featured image becomes a reference to the library rather than a copy of
 * its own.
 *
 * With a media library in place, uploading the same picture again for every
 * post that mentions it is waste, and a path column cannot say whether the file
 * is still there. A row in `cms_media` can: deleting it nulls the reference
 * instead of leaving a broken `<img>`.
 *
 * Anything already uploaded through the old per-post field is adopted into the
 * library rather than dropped.
 */
return new class extends Migration
{
    private const TABLES = ['cms_posts', 'cms_pages'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->foreignId('image_media_id')->nullable()->after('body')
                    ->constrained('cms_media')->nullOnDelete();
                $blueprint->index('image_media_id', $table.'_image_media_id_idx');
            });
        }

        foreach (self::TABLES as $table) {
            $this->adoptExistingImages($table);
        }

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('image_path');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->string('image_path')->nullable()->after('body');
                $blueprint->dropIndex($table.'_image_media_id_idx');
                $blueprint->dropConstrainedForeignId('image_media_id');
            });
        }
    }

    /** Moves whatever the per-row upload field held into the library. */
    private function adoptExistingImages(string $table): void
    {
        $rows = DB::table($table)->whereNotNull('image_path')->get(['id', 'image_path']);

        foreach ($rows as $row) {
            $media = Media::query()->firstOrCreate(
                ['path' => $row->image_path],
                [
                    'original_name' => basename((string) $row->image_path),
                    'mime_type' => 'image/jpeg',
                    'size' => 0,
                ],
            );

            DB::table($table)->where('id', $row->id)->update(['image_media_id' => $media->id]);
        }
    }
};
