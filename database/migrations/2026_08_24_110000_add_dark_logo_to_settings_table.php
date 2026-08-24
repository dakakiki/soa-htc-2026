<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second logo, for light surfaces.
 *
 * The brand logo is white by design, which works on the navy the site used to
 * carry everywhere. The public front page (ADR-0043) puts a light header above
 * the fold, and a white logo on off-white is simply not there — recolouring an
 * uploaded image is not something CSS can do reliably, so the dark variant is
 * an asset in its own right.
 *
 * Nullable on purpose: a site that has not uploaded one falls back to the site
 * name in words rather than rendering something invisible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('logo_dark_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('logo_dark_path');
        });
    }
};
