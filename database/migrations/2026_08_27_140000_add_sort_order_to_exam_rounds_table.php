<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rounds run in an order — Preliminary, then National, and so on — and
     * every select, filter and results grid had been reading that order off
     * the row id. That works only for as long as nobody adds a round in the
     * middle or renames one, so give the order a column an admin can change.
     */
    public function up(): void
    {
        Schema::table('exam_rounds', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('active');
            $table->index('sort_order');
        });

        // Keep the order every existing install already had: the legacy round
        // numbers ran in competition order, and anything added since falls in
        // behind them by id.
        $position = 0;
        $ids = DB::table('exam_rounds')
            ->orderByRaw('legacy_id is null')
            ->orderBy('legacy_id')
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            DB::table('exam_rounds')->where('id', $id)->update(['sort_order' => ++$position]);
        }
    }

    public function down(): void
    {
        Schema::table('exam_rounds', function (Blueprint $table) {
            $table->dropIndex(['sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
