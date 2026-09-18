<?php

declare(strict_types=1);

namespace App\Domain\Communication\Support;

use App\Domain\Communication\Enums\PushOutcome;
use App\Domain\Communication\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Hands one notification to whichever push service the browser chose.
 *
 * 🔴 No third party is configured anywhere. The subscription's `endpoint` is an
 * address the BROWSER picked — Google's service for Chrome, Mozilla's for
 * Firefox, Apple's for Safari — and this posts to it, signed with the
 * application's own VAPID key pair (RFC 8292). There is no Firebase project, no
 * SDK and no API key; `minishlink/web-push` does not support FCM at all.
 *
 * 🪤 One class, one network call, and everything else around it — which is what
 * makes it replaceable in a test. The suite binds a fake over this so that
 * nothing signs, encrypts or dials out: a test that needs working EC key
 * generation is a test that fails on a machine whose OpenSSL is configured
 * differently, and says nothing about the application when it does.
 */
class PushSender
{
    private ?WebPush $client = null;

    /** Whether this installation can send at all — a pair of keys, or nothing. */
    public function configured(): bool
    {
        return is_string(config('push.vapid.public')) && config('push.vapid.public') !== ''
            && is_string(config('push.vapid.private')) && config('push.vapid.private') !== '';
    }

    /**
     * @param  array<string, mixed>  $payload  What the service worker is handed.
     */
    public function send(PushSubscription $to, array $payload): PushOutcome
    {
        if (! $this->configured()) {
            return PushOutcome::Failed;
        }

        try {
            $report = $this->client()->sendOneNotification(
                Subscription::create([
                    'endpoint' => $to->endpoint,
                    'keys' => ['p256dh' => $to->p256dh, 'auth' => $to->auth],
                ]),
                (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
        } catch (Throwable) {
            /*
             * A refused address, a signing failure, a push service having a bad
             * afternoon. One notification's problem, never the run's — the next
             * coordinator in the batch has done nothing wrong.
             */
            return PushOutcome::Failed;
        }

        if ($report->isSuccess()) {
            return PushOutcome::Sent;
        }

        return $report->isSubscriptionExpired() ? PushOutcome::Gone : PushOutcome::Failed;
    }

    private function client(): WebPush
    {
        return $this->client ??= new WebPush(['VAPID' => [
            'subject' => (string) config('push.vapid.subject'),
            'publicKey' => (string) config('push.vapid.public'),
            'privateKey' => (string) config('push.vapid.private'),
        ]]);
    }
}
