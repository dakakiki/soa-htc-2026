<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Applications from people asking to become school coordinators (ADR-0053).
 *
 * A row here is an APPLICATION, not an account. The legacy app wrote the
 * applicant straight into `users` with `active = 0`, which meant a stranger who
 * typed an address took that address out of the unique index whether or not
 * anyone ever approved them, and the Users screen filled up with people who had
 * never been let in. Keeping applications in their own table means the account
 * is created at the moment of approval and not before — and a declined applicant
 * leaves nothing behind that blocks the next one.
 *
 * The password is hashed on the way in and carried to the account it becomes:
 * the applicant chose it, and nobody — reviewer included — ever sees it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coordinator_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Deliberately NOT unique. Uniqueness belongs to `users`, which is
            // where an address is actually spoken for; here the same address may
            // legitimately appear twice (declined, then applying again). The
            // request rejects an address that already holds an account or a
            // pending application, which is the rule people actually mean.
            $table->string('email')->index();
            $table->string('phone', 100)->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->string('password');

            // The signed venue approval. Stored on the PRIVATE disk and served
            // through a permission-gated route: it carries a school's letterhead
            // and someone's signature, and the applicant is a stranger until a
            // reviewer says otherwise. `users.file_path` (public disk) is for
            // documents of people already inside.
            $table->string('document_path');
            $table->string('document_name');
            $table->string('document_mime', 120);
            $table->unsignedInteger('document_size');

            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            // Why it was declined. Shown to nobody but the reviewers — the
            // applicant's e-mail says it was not approved, not what a reviewer
            // wrote in the margin.
            $table->text('decline_reason')->nullable();
            // The account this application became, once approved.
            $table->foreignId('approved_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The queue's own query: pending first, oldest first.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coordinator_registrations');
    }
};
