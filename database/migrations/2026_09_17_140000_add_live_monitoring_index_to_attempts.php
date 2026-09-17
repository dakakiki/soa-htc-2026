<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An index for the one question Monitoring → Current action asks: which attempts
 * are open, and which of them is closest to its deadline.
 *
 * 🔴 Measured before adding it: `where status = 'in_progress'` was a full scan of
 * 184.389 rows, 168 ms, because nothing indexed `status` at all. That is a
 * screen polling every ten seconds, several queries per poll, for three people
 * at once — and the table is at its largest on the day the screen matters.
 *
 * The pair rather than `status` alone: the list is ordered by `expires_at`, so
 * the index answers the filter and the sort in one pass. Attempts are wiped at
 * the rollover, so this never grows past one season's worth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attempts', function (Blueprint $table): void {
            $table->index(['status', 'expires_at'], 'attempts_live_index');
        });
    }

    public function down(): void
    {
        Schema::table('attempts', function (Blueprint $table): void {
            $table->dropIndex('attempts_live_index');
        });
    }
};
