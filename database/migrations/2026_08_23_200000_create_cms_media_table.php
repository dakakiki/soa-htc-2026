<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The media library: files uploaded once and reused wherever the site needs
 * them — inside an article, as a cover, on a page.
 *
 * Dimensions are stored because the picker wants to show them and the editor
 * wants them on the inserted <img>; reading them back off the disk for every
 * listing would be a stat call per thumbnail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_media', function (Blueprint $table) {
            $table->id();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            // What a screen reader announces, and the caption a picker shows.
            $table->string('alt')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_media');
    }
};
