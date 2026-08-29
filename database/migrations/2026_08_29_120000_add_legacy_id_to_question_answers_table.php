<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The option a competitor picked in the legacy application is recorded as a
 * `reply_id`, and until now nothing on our side carried that id — so a migrated
 * answer could only be matched back by its position in the list, which is a
 * guess the moment an option is reordered or one is dropped.
 *
 * Every other migrated entity already has this column. Answers were the one
 * place it was missing, and it only became load-bearing when the round in play
 * brought its two million answers with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_answers', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->after('position');
            $table->index('legacy_id');
        });
    }

    public function down(): void
    {
        Schema::table('question_answers', function (Blueprint $table) {
            $table->dropIndex(['legacy_id']);
            $table->dropColumn('legacy_id');
        });
    }
};
