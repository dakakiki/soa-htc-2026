<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record the country/venue scope of each publish action (ADR-0024). Since
     * publishing is scoped by population, the audit must say which country/venue
     * was revealed — a null pair means the whole (season) population.
     */
    public function up(): void
    {
        Schema::table('publication_batches', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->after('scope_id')->constrained('countries')->nullOnDelete();
            $table->foreignId('school_id')->nullable()->after('country_id')->constrained('schools')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('publication_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
            $table->dropConstrainedForeignId('country_id');
        });
    }
};
