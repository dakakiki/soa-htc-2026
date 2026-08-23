<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A question's number now comes from its position in the test, not from text
 * typed into the title, so the title is an optional heading: most questions are
 * just an answer set under a shared passage and have nothing to put there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('title', 3000)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Titles cleared while the column was nullable would break a NOT NULL
        // column, so give them an empty string on the way back.
        DB::table('questions')->whereNull('title')->update(['title' => '']);

        Schema::table('questions', function (Blueprint $table) {
            $table->string('title', 3000)->nullable(false)->change();
        });
    }
};
