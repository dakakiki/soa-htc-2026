<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Result publication (Faza 5 Slice 5c, CC-10 / ADR-0020). A competitor sees no
     * score until their attempt is published. An admin publishes (or unpublishes)
     * a whole exam/round or a single test at once; each action is recorded in
     * `publication_batches` for audit.
     */
    public function up(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('grading_status');
            $table->foreignId('published_by')->nullable()->after('published_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('publication_batches', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 20); // test | exam
            $table->unsignedBigInteger('scope_id');
            $table->string('action', 20);      // publish | unpublish
            $table->unsignedInteger('attempts_count');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_batches');

        Schema::table('attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_by');
            $table->dropColumn('published_at');
        });
    }
};
