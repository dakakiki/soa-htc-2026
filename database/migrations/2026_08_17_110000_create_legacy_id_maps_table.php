<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration lineage map (04_LEGACY_MIGRATION_PLAN §6): records that a legacy
 * source row (source + table + primary/business key) resolved to a given target
 * entity id. It is what lets a many-to-one dedup (several legacy schools merged
 * into one) stay reconcilable — every legacy id still resolves to its target —
 * and makes every importer idempotent and auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_id_maps', function (Blueprint $table) {
            $table->id();
            // Which legacy export/season the row came from (one source for now).
            $table->string('source', 50)->default('soa2024');
            $table->string('source_table', 100);
            $table->unsignedBigInteger('source_pk');
            $table->string('target_type', 50);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->unique(['source', 'source_table', 'source_pk', 'target_type'], 'legacy_map_unique');
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_id_maps');
    }
};
