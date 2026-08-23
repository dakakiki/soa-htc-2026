<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a question labels its options — "a) b) c)", "A) B) C)", "1) 2) 3)" — or
 * null for a plain list. Authors were typing the marker into the answer text,
 * which tied it to the order it was written in; storing the style lets the app
 * render markers from each option's position instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('answer_numbering', 20)->nullable()->after('question_type');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('answer_numbering');
        });
    }
};
