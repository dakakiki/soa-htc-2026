<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Site title shown next to the logo. Rich text (admin-authored HTML) so the
     * name can carry its own emphasis and brand colours; nullable because the
     * app name from the message catalog is the fallback. Part of the public
     * `/api/theme` payload, ready for the public/CMS header.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('site_title')->nullable()->after('logo_icon_path');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('site_title');
        });
    }
};
