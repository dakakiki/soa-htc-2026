<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which round is being run right now. The season already carries its own
     * `round_number` — that is which edition of the contest this is, the 14th —
     * and it says nothing about whether the Preliminary or the National is the
     * one in play this week. Separate question, separate column.
     *
     * At most one round is current; the controller clears the others when one
     * is set. Nothing is marked on arrival: nobody has said yet, and guessing
     * would put a claim on the screen that no administrator made.
     */
    public function up(): void
    {
        Schema::table('exam_rounds', function (Blueprint $table) {
            $table->boolean('is_current')->default(false)->after('sort_order');
            $table->index('is_current');
        });
    }

    public function down(): void
    {
        Schema::table('exam_rounds', function (Blueprint $table) {
            $table->dropIndex(['is_current']);
            $table->dropColumn('is_current');
        });
    }
};
