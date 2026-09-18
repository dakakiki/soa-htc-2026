<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a notification is delivered to: one row per DEVICE, not per person.
 *
 * A coordinator with a phone and a tablet subscribes twice, and each browser
 * hands back its own endpoint. That is why this is not a column on `users`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * 🔴 The address the browser chose, and the reason no third-party
             * account is involved: Chrome's is on Google's push service,
             * Firefox's on Mozilla's, Safari's on Apple's. The server posts
             * here, signed with this application's own VAPID key.
             *
             * 🪤 Long, and not 255. FCM endpoints run past 200 characters
             * already and nothing in the spec caps them; a truncated endpoint is
             * a subscription that fails for ever with no obvious cause. TEXT
             * cannot be indexed whole on MySQL, so uniqueness is kept on a hash
             * of it instead.
             */
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();

            // The two keys the browser generates, which the payload is
            // encrypted to. Without them a notification can only be sent empty.
            $table->string('p256dh', 255);
            $table->string('auth', 255);

            /*
             * What the person was using when they subscribed, so a stale row can
             * be recognised on a screen. Never matched on: a browser rewrites it
             * on every update.
             */
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('last_sent_at')->nullable();

            $table->timestamps();

            // What sending asks: every device belonging to these people.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
