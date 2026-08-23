<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Four free brand-palette slots on top of the semantic tokens. They carry no
     * fixed meaning in the admin (nothing is wired to them by default) — they are
     * the house palette the public/CMS side will paint with, editable in one place.
     * Defaults are the SOA palette the owner supplied.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('color_palette_1', 7)->default('#fbba00')->after('color_border');
            $table->string('color_palette_2', 7)->default('#f39200')->after('color_palette_1');
            $table->string('color_palette_3', 7)->default('#97bddd')->after('color_palette_2');
            $table->string('color_palette_4', 7)->default('#003758')->after('color_palette_3');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['color_palette_1', 'color_palette_2', 'color_palette_3', 'color_palette_4']);
        });
    }
};
