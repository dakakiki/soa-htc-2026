<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Application-wide branding/theme settings, kept as a single row (id = 1).
     * Holds the uploaded logo + icon and the brand colour tokens applied across
     * the SPA at runtime via CSS variables.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('logo_path')->nullable();
            // Square icon reused for the collapsed sidebar and the browser favicon.
            $table->string('logo_icon_path')->nullable();
            // Brand colour tokens (hex, e.g. #2563eb). Defaults preserve the current look.
            $table->string('color_primary', 7)->default('#2563eb');
            $table->string('color_primary_hover', 7)->default('#1d4ed8');
            $table->string('color_primary_soft', 7)->default('#eff6ff');
            $table->string('color_on_primary', 7)->default('#ffffff');
            $table->string('color_accent', 7)->default('#0d9488');
            $table->string('color_accent_hover', 7)->default('#0f766e');
            $table->string('color_link', 7)->default('#2563eb');
            $table->string('color_border', 7)->default('#e5e7eb');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
