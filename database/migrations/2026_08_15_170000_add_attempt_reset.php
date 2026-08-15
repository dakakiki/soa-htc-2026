<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attempt reset (Faza 5 Slice 5e, CC-11 / ADR-0022). An admin may void a
     * competitor's attempt (with a mandatory reason) so a fresh one can be taken.
     * The voided row is kept for audit, so `unique(registration_id, test_id)`
     * (ADR-0016, one attempt) would block the new attempt. We replace it with a
     * unique over a generated `active_test_id` that is the test_id only while the
     * attempt is not void (NULL once voided) — so any number of voided rows may
     * coexist, but at most one ACTIVE attempt per (registration, test) remains a
     * hard DB guarantee. Each reset is snapshotted in `attempt_resets`.
     */
    public function up(): void
    {
        // Add the new generated column and its unique FIRST: with registration_id
        // as its leftmost column it can back the registration_id foreign key, so
        // the old composite unique can then be dropped without breaking the FK.
        // VIRTUAL (not STORED): adding a stored generated column forces a table
        // copy that MySQL refuses on a table with foreign keys (err 1215). A
        // virtual column is added in-place and can still back a unique index.
        Schema::table('attempts', function (Blueprint $table) {
            $table->unsignedBigInteger('active_test_id')
                ->virtualAs("case when status = 'void' then null else test_id end")
                ->nullable()
                ->after('test_id');

            // At most one active attempt per (registration, test); voided rows
            // (active_test_id = NULL) never collide.
            $table->unique(['registration_id', 'active_test_id']);
        });

        Schema::table('attempts', function (Blueprint $table) {
            $table->dropUnique(['registration_id', 'test_id']);
        });

        Schema::create('attempt_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->string('previous_status', 20);
            $table->decimal('previous_score', 8, 2)->nullable();
            $table->string('previous_grading_status', 20)->nullable();
            $table->timestamp('previous_published_at')->nullable();
            $table->text('reason');
            $table->foreignId('reset_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('attempt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_resets');

        // Restore the old unique first (its leftmost registration_id backs the FK)
        // before dropping the generated column's unique.
        Schema::table('attempts', function (Blueprint $table) {
            $table->unique(['registration_id', 'test_id']);
        });

        Schema::table('attempts', function (Blueprint $table) {
            $table->dropUnique(['registration_id', 'active_test_id']);
            $table->dropColumn('active_test_id');
        });
    }
};
