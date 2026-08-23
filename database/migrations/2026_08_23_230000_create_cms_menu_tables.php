<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Managed navigation (PROJECT_CONTEXT §8.6): as many menus as the site needs,
 * each an ordered tree of items.
 *
 * An item points at a page, a post or a category by foreign key rather than by
 * a stored URL, so renaming the slug moves the link with it. Only the `custom`
 * type carries a literal address, and only that type is allowed to leave the
 * site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_menus', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Stable handle for the layout to ask for ("public-header").
            $table->string('slug', 191)->unique();
            $table->timestamps();
        });

        Schema::create('cms_menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained('cms_menus')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('cms_menu_items')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);

            $table->string('type', 20);
            // A deleted target takes its menu item with it: a link to a page that
            // no longer exists is worse than a gap in the menu.
            $table->foreignId('page_id')->nullable()->constrained('cms_pages')->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained('cms_posts')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('cms_categories')->cascadeOnDelete();
            $table->string('url', 500)->nullable();

            // Null means "whatever the target is called"; a value overrides it,
            // and only for this menu item.
            $table->string('label')->nullable();
            $table->string('link_target', 20)->default('_self');
            $table->timestamps();

            $table->index(['menu_id', 'parent_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_menu_items');
        Schema::dropIfExists('cms_menus');
    }
};
