<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bring the difficulty tables up to the full legacy model: a category is a
     * grade→level scheme scoped to a stream (regular/special) and either all
     * countries or a specific set; a level maps a set of school grades to a named
     * competition level (BH, H1, S5, …).
     */
    public function up(): void
    {
        Schema::table('difficulty_categories', function (Blueprint $table) {
            $table->string('type', 20)->default('regular')->after('name');
            $table->boolean('countries_all')->default(true)->after('type');
            $table->string('status', 20)->default('active')->after('countries_all');
        });

        Schema::table('difficulty_levels', function (Blueprint $table) {
            $table->string('level_short', 20)->nullable()->after('name');
            // School grades that map to this level, e.g. [1, 2].
            $table->json('grades')->nullable()->after('level_short');
            $table->string('status', 20)->default('active')->after('position');
        });

        // Specific countries a category applies to (only when countries_all = false).
        Schema::create('difficulty_category_country', function (Blueprint $table) {
            $table->foreignId('difficulty_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->primary(['difficulty_category_id', 'country_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('difficulty_category_country');

        Schema::table('difficulty_levels', function (Blueprint $table) {
            $table->dropColumn(['level_short', 'grades', 'status']);
        });

        Schema::table('difficulty_categories', function (Blueprint $table) {
            $table->dropColumn(['type', 'countries_all', 'status']);
        });
    }
};
