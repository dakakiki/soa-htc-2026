<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a notice was read (owner, 2026-09-18: *„tap na poruku ce je uciniti
 * procitanom"*).
 *
 * 🔴 A THIRD state, and it had to be one. Until now a delivery knew two things
 * about itself — that it went out, and that it had been put away — and the bell
 * counted the second: anything not dismissed. So a coordinator who followed a
 * notification to their inbox, read the message and left it there was still
 * being told something was waiting, for ever. The only way to silence the bell
 * was ×, and × is final (ADR-0119): it would have answered "you have unread
 * messages" by destroying the message.
 *
 * Read and put away are not the same act and cannot share a column. Put away is
 * *"I am finished with this"*; read is *"I have seen it"* — and the second is
 * what the bell is actually asking about.
 *
 * 🪤 `read_at` and NOT the `seen_at` that was written down as the thing to build
 * next. That one was to mean "it was on their screen", which a browser cannot
 * honestly report; this is stamped when the person TAPS the row, which is an act
 * of theirs. It still cannot know they took it in — nothing can — but it does
 * measure what its name says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_deliveries', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('message_deliveries', function (Blueprint $table) {
            $table->dropColumn('read_at');
        });
    }
};
