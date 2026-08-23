<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit region order per country, set by drag & drop in the locations admin
     * and honoured by every region list (pickers included). Existing rows keep the
     * alphabetical order they were shown in, so nothing visibly moves on deploy.
     */
    public function up(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('name');
            $table->index(['country_id', 'position']);
        });

        $position = 0;
        $country = null;

        foreach (DB::table('regions')->orderBy('country_id')->orderBy('name')->get(['id', 'country_id']) as $region) {
            if ($region->country_id !== $country) {
                $country = $region->country_id;
                $position = 0;
            }

            DB::table('regions')->where('id', $region->id)->update(['position' => ++$position]);
        }
    }

    public function down(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->dropIndex(['country_id', 'position']);
            $table->dropColumn('position');
        });
    }
};
