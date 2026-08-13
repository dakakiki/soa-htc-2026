<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Every staff user belongs to a country (required at the API layer);
            // region is an optional narrowing used when picking schools.
            $table->foreignId('country_id')->nullable()->after('email')->constrained()->nullOnDelete();
            $table->foreignId('region_id')->nullable()->after('country_id')->constrained()->nullOnDelete();
            $table->index('country_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('country_id');
            $table->dropConstrainedForeignId('region_id');
        });
    }
};
