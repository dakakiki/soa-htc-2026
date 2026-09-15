<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages to coordinators (owner, 2026-09-15).
 *
 * The administration writes one text and decides who gets it and on which
 * channels; the same words then go out by mail, by push, and to the app. One
 * message, many deliveries — which is why there are two tables and not one.
 *
 * `messages` is what was composed. `message_deliveries` is what actually
 * happened to it, one row per person per channel: a message that says it went
 * to 412 coordinators and a mail server that refused 3 of them are both true,
 * and only the second table can say so.
 *
 * 🪤 The audience is stored as a filter, not as a list of people. A message
 * addressed to "the school coordinators of Serbia" sent in September must still
 * read that way in December, when three more of them have been added. Who it
 * actually reached is in the deliveries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            // The season the message belongs to: coordinators are assigned per
            // season, so "everyone" only means anything inside one.
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();

            // One subject for every channel: a notification title and a mail
            // subject line are the same sentence.
            $table->string('subject', 200);

            // Two bodies, because the channels are not the same medium. `body`
            // is the plain, short text a notification and the in-app notice
            // carry; `body_html` is what the mail carries, written in an editor.
            // Each is required only by the channel that uses it, so a mail-only
            // message has no plain body and a notification-only one has no HTML.
            $table->text('body')->nullable();
            $table->text('body_html')->nullable();

            // Four lists that multiply: roles AND countries AND venues AND
            // people. An empty list is not a filter, so {} addresses every
            // coordinator in the season and {"roles":[2],"countries":[7,9]}
            // addresses the country coordinators of two countries.
            //
            // 🪤 One `audience_type` was the first design and it could not say
            // that sentence: it forced a choice between "by role" and "by
            // country" when the administration means both at once.
            $table->json('audience');

            // A list, because a message can go out on more than one at once.
            // `app` is always in it: that is the channel that cannot fail.
            $table->json('channels');

            // draft | scheduled | sent
            $table->string('status', 20)->default('draft');

            // When it should go. Null means "as soon as it is sent by hand".
            $table->timestamp('send_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            // What it actually went to, written when it goes. Kept on the
            // message so a list of 200 rows does not have to count deliveries
            // for each one.
            $table->unsignedInteger('recipients_count')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            // The list screen reads by season and status, newest first.
            $table->index(['season_id', 'status']);
            // The scheduler asks one question: what is due?
            $table->index(['status', 'send_at']);
        });

        Schema::create('message_deliveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // app | mail | push
            $table->string('channel', 10);
            // pending | sent | failed
            $table->string('status', 10)->default('pending');

            $table->timestamp('sent_at')->nullable();
            // Why a delivery failed, in the words of whatever refused it.
            $table->string('error', 500)->nullable();

            // The app channel only: the coordinator has put the notice away.
            // Mail and push have no such thing to record.
            $table->timestamp('dismissed_at')->nullable();

            $table->timestamps();

            // One row per person per channel, and never two.
            $table->unique(['message_id', 'user_id', 'channel']);
            // What the app asks on every load: what is waiting for me?
            $table->index(['user_id', 'channel', 'dismissed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_deliveries');
        Schema::dropIfExists('messages');
    }
};
