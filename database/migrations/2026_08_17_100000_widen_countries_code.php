<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The legacy `el_country.country_short` codes are 2–3 characters (and 96 of them
 * are only unique at full length), so a 2-char column cannot hold them without
 * collisions. Widen `code` to 3 characters for the migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->string('code', 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->string('code', 2)->change();
        });
    }
};
