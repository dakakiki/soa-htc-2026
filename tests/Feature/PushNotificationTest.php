<?php

namespace Tests\Feature;

use App\Domain\Communication\Enums\PushOutcome;
use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Models\MessageDelivery;
use App\Domain\Communication\Models\PushSubscription;
use App\Domain\Communication\Support\MessageDispatcher;
use App\Domain\Communication\Support\PushSender;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Domain\Organization\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Web push: subscribing a browser, and what one send does to the rows.
 *
 * 🔴 Nothing here signs, encrypts or dials out. {@see PushSender} is replaced
 * with a fake, which is the whole reason it is one class with one network call:
 * a test that needed real EC key generation would fail on any machine whose
 * OpenSSL is configured differently — and say nothing about this application
 * when it did. What is worth testing is what the rows do, and that is all here.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_a_browser_is_remembered_and_can_be_forgotten(): void
    {
        $user = $this->coordinator();

        $this->actingAs($user)->postJson('/api/push/subscriptions', $this->browser())->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $user->id,
            'p256dh' => 'a-public-key',
        ]);

        $this->actingAs($user)
            ->deleteJson('/api/push/subscriptions', ['endpoint' => $this->browser()['endpoint']])
            ->assertNoContent();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    /**
     * 🔴 Subscribing twice from the same browser has to be ONE row. A browser
     * whose permission was withdrawn and given again hands back the same
     * endpoint, and a second row for it delivers every later message twice.
     */
    public function test_the_same_browser_subscribing_again_does_not_become_a_second_device(): void
    {
        $user = $this->coordinator();

        $this->actingAs($user)->postJson('/api/push/subscriptions', $this->browser())->assertCreated();
        $this->actingAs($user)->postJson('/api/push/subscriptions', $this->browser(p256dh: 'a-newer-key'))->assertCreated();

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertSame('a-newer-key', PushSubscription::query()->value('p256dh'));
    }

    /**
     * 🪤 The endpoint is not a secret — it travels in every subscription and is
     * whatever the browser says. Unsubscribing therefore has to match the person
     * as well, or one stray endpoint turns off somebody else's device.
     */
    public function test_one_person_cannot_unsubscribe_another_persons_device(): void
    {
        $mine = $this->coordinator('mine@soahtc.test');
        $theirs = $this->coordinator('theirs@soahtc.test');

        $this->actingAs($theirs)->postJson('/api/push/subscriptions', $this->browser())->assertCreated();

        $this->actingAs($mine)
            ->deleteJson('/api/push/subscriptions', ['endpoint' => $this->browser()['endpoint']])
            ->assertNoContent();

        $this->assertSame(1, PushSubscription::query()->count(), "somebody else's device is still subscribed");
    }

    /**
     * 🔴 ONE delivery row covers every device that person has. The table keeps
     * one row per person per channel, and a coordinator may carry a phone and a
     * tablet — so the row is sent when at least one of them took it.
     */
    public function test_one_delivery_row_covers_every_device_that_person_has(): void
    {
        $user = $this->coordinator();
        $this->device($user, 'https://push.example/phone');
        $this->device($user, 'https://push.example/tablet');

        $reached = [];
        $this->fakeSender(function (PushSubscription $to) use (&$reached) {
            $reached[] = $to->endpoint;

            return PushOutcome::Sent;
        });

        $result = app(MessageDispatcher::class)->deliverPushes();

        $this->assertCount(2, $reached, 'both devices are told');
        $this->assertSame(1, $result['sent'], 'and it is one delivery, not two');
        $this->assertSame(MessageDelivery::STATUS_SENT, $this->pushRow($user)->status);
    }

    /** One device refusing is not the person being unreachable. */
    public function test_a_row_is_sent_when_any_one_device_took_it(): void
    {
        $user = $this->coordinator();
        $this->device($user, 'https://push.example/phone');
        $this->device($user, 'https://push.example/tablet');

        $this->fakeSender(fn (PushSubscription $to) => $to->endpoint === 'https://push.example/phone'
            ? PushOutcome::Failed
            : PushOutcome::Sent);

        $this->assertSame(1, app(MessageDispatcher::class)->deliverPushes()['sent']);
        $this->assertSame(MessageDelivery::STATUS_SENT, $this->pushRow($user)->status);
    }

    /**
     * 🔴 A subscription the push service says is GONE is deleted, not retried.
     * The application was removed or permission withdrawn; left in place it is
     * dialled on every message for ever and the table fills with devices that
     * stopped existing months ago.
     */
    public function test_a_dead_subscription_is_removed_rather_than_tried_again(): void
    {
        $user = $this->coordinator();
        $this->device($user, 'https://push.example/deleted-app');

        $this->fakeSender(fn () => PushOutcome::Gone);

        $result = app(MessageDispatcher::class)->deliverPushes();

        $this->assertSame(1, $result['dropped']);
        $this->assertSame(0, PushSubscription::query()->count());
        // Nothing accepted it, so the row says so rather than claiming a send.
        $this->assertSame(MessageDelivery::STATUS_FAILED, $this->pushRow($user)->status);
    }

    /**
     * Nobody turned notifications on. That is not the push channel being broken,
     * and the row says which it is in its own words.
     */
    public function test_somebody_with_no_device_is_not_reported_as_a_send(): void
    {
        $user = $this->coordinator();

        $this->fakeSender(fn () => PushOutcome::Sent);

        $this->assertSame(1, app(MessageDispatcher::class)->deliverPushes()['failed']);
        $this->assertStringContainsString('No device', (string) $this->pushRow($user)->error);
    }

    /** The public key is served so a fresh installation's own key is used. */
    public function test_the_public_key_is_offered_only_when_there_is_one(): void
    {
        $user = $this->coordinator();

        config(['push.vapid.public' => null, 'push.vapid.private' => null]);
        $this->actingAs($user)->getJson('/api/push/key')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.key', null);

        config(['push.vapid.public' => 'a-public-key', 'push.vapid.private' => 'a-private-key']);
        $this->actingAs($user)->getJson('/api/push/key')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.key', 'a-public-key');
    }

    public function test_an_unauthenticated_browser_cannot_subscribe(): void
    {
        $this->postJson('/api/push/subscriptions', $this->browser())->assertUnauthorized();
    }

    /**
     * 🔴 The tap has to land on the message, not on a list to search.
     *
     * 🪤 And it names the MESSAGE, never the delivery. This payload is built on
     * the PUSH-channel row; the row the reader will find in their inbox is the
     * APP-channel one, and the two have different ids — pointing at this one
     * would highlight nothing, on every message, for ever.
     */
    public function test_the_tap_lands_on_the_message_and_not_merely_on_the_inbox(): void
    {
        $user = $this->coordinator();
        $this->device($user, 'https://push.example/phone');

        $pushDelivery = $this->pushRow($user);

        $seen = [];
        $this->fakeSender(function (PushSubscription $to, array $payload) use (&$seen) {
            $seen = $payload;

            return PushOutcome::Sent;
        });

        app(MessageDispatcher::class)->deliverPushes();

        $this->assertSame('/app/messages?notice='.$pushDelivery->message_id, $seen['url']);
        $this->assertNotSame(
            '/app/messages?notice='.$pushDelivery->id,
            $seen['url'],
            'that is the push delivery, which the inbox never shows',
        );
    }

    /**
     * 🪤 The icon travels WITH the notification, because only the server knows
     * where it is: it is uploaded through Settings and stored under a hashed
     * name. A fixed path written into `sw.js` — which is what the first version
     * did — does not even 404: it falls through to the front controller, answers
     * 200 with the application's HTML, and the browser quietly draws no icon.
     */
    public function test_the_payload_carries_the_icon_rather_than_the_worker_guessing_it(): void
    {
        $user = $this->coordinator();
        $this->device($user, 'https://push.example/phone');

        Storage::fake('public');
        Storage::disk('public')->put('branding/hashed-name.png', 'not-really-a-png');
        Setting::current()->update(['logo_icon_path' => 'branding/hashed-name.png']);

        $seen = [];
        $this->fakeSender(function (PushSubscription $to, array $payload) use (&$seen) {
            $seen = $payload;

            return PushOutcome::Sent;
        });

        app(MessageDispatcher::class)->deliverPushes();

        $this->assertArrayHasKey('icon', $seen);
        $this->assertStringContainsString('branding/hashed-name.png', (string) $seen['icon']);
    }

    /**
     * The half of this that lives in the browser. The front has no test runner
     * and is not getting one (ADR-0074, withdrawn), so the file is read — as
     * `ManifestTest` reads the router.
     *
     * 🔴 A push MUST show a notification. Chrome allows a very small number of
     * silent ones and then withdraws the permission outright: the subscription
     * keeps working, nothing arrives, and nothing anywhere says why.
     */
    public function test_the_service_worker_answers_a_push_and_a_tap(): void
    {
        $worker = $this->serviceWorker();

        $this->assertStringContainsString("addEventListener('push'", $worker);
        $this->assertStringContainsString('showNotification(', $worker);
        $this->assertStringContainsString("addEventListener('notificationclick'", $worker);
    }

    /**
     * 🔴 And it still caches NOTHING. This is an examination site: a worker
     * holding the shell would serve yesterday's HTML after a deploy, pointing at
     * hashed assets that no longer exist — a white screen, mid-season, for
     * whoever had visited before. Adding push is exactly the kind of change
     * during which a cache gets added by habit.
     */
    public function test_the_service_worker_still_caches_nothing(): void
    {
        $worker = $this->serviceWorker();

        $this->assertSame(0, preg_match('/\bcaches\s*\./', $worker), 'the worker has been given a cache');
        $this->assertSame(0, preg_match('/\brespondWith\s*\(/', $worker), 'the worker has started answering fetches');
    }

    /** 🔴 And it names no address of its own — see the payload test above. */
    public function test_the_service_worker_does_not_guess_where_the_icon_is(): void
    {
        $this->assertSame(
            0,
            preg_match('#/storage/branding/[A-Za-z0-9._-]+#', $this->serviceWorker()),
            'sw.js has a hard-coded icon path again; only the server knows where the icon is.',
        );
    }

    private function serviceWorker(): string
    {
        return (string) file_get_contents(public_path('sw.js'));
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function browser(string $endpoint = 'https://push.example/one', string $p256dh = 'a-public-key'): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => $p256dh, 'auth' => 'an-auth-secret']];
    }

    /** Swap the one class that touches the network for something that does not. */
    private function fakeSender(callable $answer): void
    {
        $this->app->instance(PushSender::class, new class($answer) extends PushSender
        {
            public function __construct(private $answer) {}

            public function configured(): bool
            {
                return true;
            }

            public function send(PushSubscription $to, array $payload): PushOutcome
            {
                return ($this->answer)($to, $payload);
            }
        });
    }

    private function device(User $user, string $endpoint): PushSubscription
    {
        return PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
            'p256dh' => 'a-public-key',
            'auth' => 'an-auth-secret',
        ]);
    }

    /**
     * A message already dispatched on the push channel, so the tests above are
     * about DELIVERING rather than about writing rows.
     */
    private function pushRow(User $user): MessageDelivery
    {
        return MessageDelivery::query()
            ->where('user_id', $user->id)
            ->where('channel', 'push')
            ->firstOrFail();
    }

    private function coordinator(string $email = 'reader@soahtc.test'): User
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $user = User::factory()->create(['email' => $email]);

        SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => Role::where('key', SystemRole::SchoolCoordinator->value)->value('id'),
            'status' => 'active',
        ]);

        $message = Message::create([
            'season_id' => $season->id,
            'subject' => 'Preliminary round closes Friday',
            'body' => 'Please make sure every room has finished Reading.',
            'channels' => ['app', 'push'],
            'status' => 'sent',
            'sent_at' => now(),
            'audience' => [],
            'recipients_count' => 1,
            'created_by' => $user->id,
        ]);

        /*
         * 🪤 The app row FIRST, and not only because a message with both
         * channels really has both. It pushes the push row's id past the
         * message's, so a test comparing the two is comparing two different
         * numbers — with one delivery they are both 1 on a fresh SQLite
         * database and an assertion about which one is used proves nothing.
         */
        MessageDelivery::create([
            'message_id' => $message->id,
            'user_id' => $user->id,
            'channel' => 'app',
            'status' => MessageDelivery::STATUS_SENT,
            'sent_at' => now(),
        ]);

        MessageDelivery::create([
            'message_id' => $message->id,
            'user_id' => $user->id,
            'channel' => 'push',
            'status' => MessageDelivery::STATUS_PENDING,
        ]);

        return $user;
    }
}
