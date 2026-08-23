<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public website's content (PROJECT_CONTEXT §8.6): categories, posts and
 * pages, plus the redirects a slug change leaves behind.
 *
 * Every row carries `locale` and `translation_group`: only English exists now,
 * but a second language is then a row rather than a schema change. Rows of one
 * group are the same content in different languages; the group id of the first
 * row is its own id.
 *
 * The tables are deliberately unrelated to the competition core — publishing a
 * page must not touch an attempt, a timer or a result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_categories', function (Blueprint $table) {
            $table->id();
            // A category may sit under another; a flat tree is simply all-null.
            $table->foreignId('parent_id')->nullable()->constrained('cms_categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug', 191);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('position')->default(0);
            $table->string('locale', 5)->default('en');
            $table->unsignedBigInteger('translation_group')->nullable()->index();
            $table->timestamps();

            $table->unique(['locale', 'slug']);
            $table->index('status');
        });

        Schema::create('cms_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug', 191);
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();
            $table->string('image_path')->nullable();
            // The author is a credit, not an owner: deleting the account leaves
            // the post standing.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('locale', 5)->default('en');
            $table->unsignedBigInteger('translation_group')->nullable()->index();
            $table->timestamps();

            $table->unique(['locale', 'slug']);
            // The public list is "published, newest first" and nothing else.
            $table->index(['status', 'published_at']);
        });

        Schema::create('cms_category_post', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained('cms_posts')->cascadeOnDelete();
            // Deleting a category must not take its posts with it, so the pivot
            // row goes and the post stays.
            $table->foreignId('category_id')->constrained('cms_categories')->cascadeOnDelete();

            $table->primary(['post_id', 'category_id']);
            $table->index('category_id');
        });

        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug', 191);
            $table->longText('body')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('locale', 5)->default('en');
            $table->unsignedBigInteger('translation_group')->nullable()->index();
            $table->timestamps();

            $table->unique(['locale', 'slug']);
            $table->index(['status', 'published_at']);
        });

        // A published address keeps working after its slug changes. Kept as the
        // path rather than a foreign key to a slug, so a chain of renames still
        // resolves in one lookup.
        Schema::create('cms_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path', 191)->unique();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_redirects');
        Schema::dropIfExists('cms_pages');
        Schema::dropIfExists('cms_category_post');
        Schema::dropIfExists('cms_posts');
        Schema::dropIfExists('cms_categories');
    }
};
