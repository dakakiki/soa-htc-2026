<?php

namespace Tests\Feature;

use App\Domain\Communication\Enums\MessageChannel;
use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Models\MessageDelivery;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\School;
use App\Mail\CoordinatorMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MessageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@soahtc.test')->firstOrFail();
    }

    private function roleId(string $key): int
    {
        return Role::where('key', $key)->firstOrFail()->id;
    }

    /**
     * A coordinator of the active season, which is the only kind a message
     * can reach.
     */
    private function coordinator(string $email, string $roleKey = SystemRole::SchoolCoordinator->value, ?School $school = null): User
    {
        $school ??= School::firstOrFail();

        $this->actingAs($this->admin())
            ->postJson('/api/coordinators', [
                'name' => 'Coordinator '.$email,
                'email' => $email,
                'password' => 'secret-password',
                'country_id' => $school->country_id,
                'role_id' => $this->roleId($roleKey),
                // Both levels are created with a venue: the administration
                // asks for at least one whichever level it is.
                'school_ids' => [$school->id],
            ])
            ->assertCreated();

        return User::where('email', $email)->firstOrFail();
    }

    /**
     * What an audience comes to, asked the way the screen asks it.
     *
     * Tests count DIFFERENCES rather than totals: the seeded season already
     * holds an administrator, who is a user of it like anybody else, and a
     * test that hard-codes "1" is really asserting what the seeder does.
     *
     * @param  array<string, list<int>>  $audience
     */
    private function countFor(array $audience = []): int
    {
        return (int) $this->actingAs($this->admin())
            ->postJson('/api/messages/recipients', ['audience' => $audience])
            ->assertOk()
            ->json('data.count');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'subject' => 'Entry closes on 20 September',
            'body' => 'Every child needs a candidate number before entry closes.',
            'body_html' => '<p>Every child needs a candidate number.</p>',
            'audience' => ['roles' => [], 'countries' => [], 'venues' => [], 'users' => []],
            'channels' => ['app', 'mail'],
            'status' => 'draft',
        ], $overrides);
    }

    public function test_admin_can_write_a_draft(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.subject', 'Entry closes on 20 September');

        $this->assertDatabaseCount('messages', 1);
    }

    /**
     * 🔴 The APP channel is always among them, whatever was asked for (owner,
     * 2026-09-18: *„nema nikakvog smisla da se salju notifikacije koje nece biti
     * u inboxu"*). It is the only one that KEEPS the message — mail leaves the
     * building and a notification is gone the moment it is swiped away.
     *
     * 🪤 Asserted against the API and not the screen. The enum has claimed this
     * about itself since it was written while the form offered a checkbox that
     * contradicted it; a rule that lives in a form is a rule until somebody
     * posts JSON.
     */
    public function test_the_app_channel_is_added_to_whatever_was_asked_for(): void
    {
        config(['push.vapid.public' => 'a-public-key', 'push.vapid.private' => 'a-private-key']);

        foreach ([['mail'], ['push'], ['mail', 'push'], []] as $asked) {
            $channels = $this->actingAs($this->admin())
                ->postJson('/api/messages', $this->payload(['channels' => $asked]))
                ->assertCreated()
                ->json('data.channels');

            $this->assertContains(
                MessageChannel::App->value,
                $channels,
                'asked for ['.implode(', ', $asked).'] and the app channel was left out',
            );
        }
    }

    /** And it is not added twice when it was asked for. */
    public function test_the_app_channel_is_not_doubled(): void
    {
        $channels = $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload(['channels' => ['app', 'mail']]))
            ->assertCreated()
            ->json('data.channels');

        $this->assertSame(['app', 'mail'], $channels);
    }

    /**
     * ⚠️ The cost of that rule, said plainly: every message now needs its short
     * plain line, including one whose real content is a long formal mail. That
     * line is what a coordinator can act on from a corridor.
     */
    public function test_every_message_needs_the_plain_text_now(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload(['channels' => ['mail'], 'body' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    /** A message that only goes to the app still needs no HTML. */
    public function test_a_message_that_is_not_posted_needs_no_letter(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload(['channels' => [], 'body_html' => null]))
            ->assertCreated()
            ->assertJsonPath('data.body_html', null)
            ->assertJsonPath('data.channels', ['app']);
    }

    public function test_a_notification_only_message_needs_no_html(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload(['channels' => ['app'], 'body_html' => null]))
            ->assertCreated()
            ->assertJsonPath('data.body_html', null);
    }

    public function test_the_app_channel_still_needs_its_text(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload(['channels' => ['app'], 'body' => ' ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    public function test_the_mail_channel_still_needs_its_text(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload(['channels' => ['mail'], 'body_html' => '<p> </p>']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('body_html');
    }

    /**
     * 🪤 An empty list is no longer a refusal. It means "the app and nowhere
     * else", which is a real thing to send — the validation says `present`
     * rather than `min:1` for exactly that reason.
     */
    public function test_asking_for_no_channel_sends_it_to_the_app(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload(['channels' => []]))
            ->assertCreated()
            ->assertJsonPath('data.channels', ['app']);
    }

    /**
     * 🔴 Refused where THIS installation cannot send it — a deployment that has
     * not run `push:keys` has nowhere to push to, and accepting the channel
     * would write deliveries nothing can ever pay off.
     *
     * 🪤 It refused push outright until 2026-09-18, when the channel did not
     * exist. The rule is narrower now, so the test says which rule it is.
     */
    public function test_notification_is_refused_where_the_installation_has_no_key(): void
    {
        config(['push.vapid.public' => null, 'push.vapid.private' => null]);

        $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload(['channels' => ['push']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('channels');
    }

    /**
     * An empty list is not a filter. The administration is allowed to mean
     * "everybody", and saying so should not need a special case.
     */
    public function test_an_empty_audience_is_everyone(): void
    {
        $before = $this->countFor();
        $this->coordinator('one@soahtc.test');

        $this->assertSame($before + 1, $this->countFor());
    }

    public function test_a_scheduled_message_needs_a_time(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload(['status' => 'scheduled']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('send_at');
    }

    /**
     * 🔴 The count beside the audience picker and the people the message goes
     * to are one query. If these two numbers can differ, the screen is lying
     * before anything has even been sent.
     */
    public function test_the_preview_count_is_what_the_message_actually_reaches(): void
    {
        $this->coordinator('one@soahtc.test');
        $this->coordinator('two@soahtc.test');

        $preview = $this->actingAs($this->admin())
            ->postJson('/api/messages/recipients', ['audience' => []])
            ->assertOk()
            ->json('data.count');

        $id = $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload())
            ->json('data.id');

        $this->actingAs($this->admin())->postJson("/api/messages/{$id}/send")->assertOk();

        $this->assertSame($preview, Message::findOrFail($id)->recipients_count);
        $this->assertSame($preview, MessageDelivery::where('message_id', $id)->where('channel', 'app')->count());
    }

    public function test_a_country_narrows_to_that_country(): void
    {
        $school = School::firstOrFail();
        // The seeder puts every school in one country, and this test needs two.
        $elsewhere = Country::where('id', '!=', $school->country_id)->firstOrFail();
        $abroad = School::create([
            'country_id' => $elsewhere->id,
            'name' => 'School Abroad',
            'status' => 'active',
        ]);

        $here = ['countries' => [$school->country_id]];
        $before = $this->countFor($here);

        $this->coordinator('inside@soahtc.test', school: $school);
        $this->coordinator('outside@soahtc.test', school: $abroad);

        // One of the two was added to this country; the other one abroad is
        // not in the answer.
        $this->assertSame($before + 1, $this->countFor($here));
    }

    /**
     * 🔴 The whole point of the four lists: "the school coordinators of
     * these countries" is one message. The first design stored a single
     * audience type and could not say it (owner, 2026-09-15).
     */
    public function test_role_and_country_narrow_together(): void
    {
        $school = School::firstOrFail();
        $elsewhere = Country::where('id', '!=', $school->country_id)->firstOrFail();
        $abroad = School::create([
            'country_id' => $elsewhere->id,
            'name' => 'School Abroad',
            'status' => 'active',
        ]);

        // Two in the country, of different levels; one of the same level abroad.
        $this->coordinator('school-here@soahtc.test', school: $school);
        $this->coordinator('country-here@soahtc.test', SystemRole::CountryCoordinator->value, $school);
        $this->coordinator('school-abroad@soahtc.test', school: $abroad);

        $schoolRole = $this->roleId(SystemRole::SchoolCoordinator->value);

        // The role alone: both school coordinators, here and abroad.
        $this->assertSame(2, $this->countFor(['roles' => [$schoolRole]]));

        // Both together: the school coordinators of this country, and nobody
        // else — not the country coordinator beside them, not the school
        // coordinator abroad.
        $this->assertSame(1, $this->countFor([
            'roles' => [$schoolRole],
            'countries' => [$school->country_id],
        ]));
    }

    /** A venue narrows to the people who run it, whatever else is set. */
    public function test_a_venue_narrows_to_its_coordinators(): void
    {
        $school = School::firstOrFail();
        $other = School::create([
            'country_id' => $school->country_id,
            'name' => 'Second School',
            'status' => 'active',
        ]);

        $this->coordinator('first@soahtc.test', school: $school);
        $this->coordinator('second@soahtc.test', school: $other);

        $this->assertSame(1, $this->countFor(['venues' => [$other->id]]));
    }

    /** Down to one person, which is the old "one coordinator" case. */
    public function test_naming_people_narrows_to_them(): void
    {
        $one = $this->coordinator('one@soahtc.test');
        $this->coordinator('two@soahtc.test');

        $this->assertSame(1, $this->countFor(['users' => [$one->id]]));
    }

    /**
     * A competitor is not a user: no account, no address, nothing to write to.
     * Every other level of the season can be written to, administrators
     * included (owner, 2026-09-15).
     */
    public function test_competitors_are_never_recipients(): void
    {
        $this->coordinator('one@soahtc.test');

        // Whatever the season holds, asking for competitors asks for nobody.
        $this->assertGreaterThan(0, $this->countFor());
        $this->assertSame(0, $this->countFor(['roles' => [$this->roleId(SystemRole::Student->value)]]));
    }

    /**
     * Sending writes the in-app notices at once and leaves the mails owed:
     * four hundred addresses do not belong in a web request.
     */
    public function test_sending_delivers_in_the_app_and_leaves_the_mail_owed(): void
    {
        Mail::fake();
        $this->coordinator('reader@soahtc.test');

        $id = $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload())
            ->json('data.id');

        $this->actingAs($this->admin())
            ->postJson("/api/messages/{$id}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $this->assertDatabaseHas('message_deliveries', [
            'message_id' => $id, 'channel' => 'app', 'status' => 'sent',
        ]);
        $this->assertDatabaseHas('message_deliveries', [
            'message_id' => $id, 'channel' => 'mail', 'status' => 'pending',
        ]);
        Mail::assertNothingSent();
    }

    public function test_the_command_sends_the_mail_that_was_owed(): void
    {
        Mail::fake();
        $this->coordinator('reader@soahtc.test');

        // Addressed to one level, so what the command does is the only thing
        // the count can be measuring.
        $id = $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload([
                'audience' => ['roles' => [$this->roleId(SystemRole::SchoolCoordinator->value)]],
            ]))
            ->json('data.id');
        $this->actingAs($this->admin())->postJson("/api/messages/{$id}/send")->assertOk();

        $this->artisan('messages:send')->assertSuccessful();

        Mail::assertSent(CoordinatorMessage::class, 1);
        $this->assertDatabaseHas('message_deliveries', [
            'message_id' => $id, 'channel' => 'mail', 'status' => 'sent',
        ]);
    }

    public function test_a_scheduled_message_goes_when_its_hour_has_come(): void
    {
        Mail::fake();
        $this->coordinator('reader@soahtc.test');

        $id = $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload([
                'status' => 'scheduled',
                'send_at' => now()->subMinute()->toDateTimeString(),
            ]))
            ->json('data.id');

        $this->artisan('messages:send')->assertSuccessful();

        $this->assertSame('sent', Message::findOrFail($id)->status->value);
    }

    public function test_a_message_scheduled_for_later_stays_put(): void
    {
        $this->coordinator('reader@soahtc.test');

        $id = $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload([
                'status' => 'scheduled',
                'send_at' => now()->addHour()->toDateTimeString(),
            ]))
            ->json('data.id');

        $this->artisan('messages:send')->assertSuccessful();

        $this->assertSame('scheduled', Message::findOrFail($id)->status->value);
    }

    /**
     * What people were told is a record. Editing it afterwards would make the
     * record disagree with every inbox that already holds it.
     */
    public function test_a_sent_message_cannot_be_edited_or_deleted(): void
    {
        $this->coordinator('reader@soahtc.test');

        $id = $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload())
            ->json('data.id');
        $this->actingAs($this->admin())->postJson("/api/messages/{$id}/send")->assertOk();

        $this->actingAs($this->admin())
            ->putJson("/api/messages/{$id}", $this->payload(['subject' => 'Something else']))
            ->assertStatus(422);

        $this->actingAs($this->admin())
            ->deleteJson("/api/messages/{$id}")
            ->assertStatus(422);
    }

    public function test_a_coordinator_sees_what_was_addressed_to_them_and_can_put_it_away(): void
    {
        $reader = $this->coordinator('reader@soahtc.test');

        $id = $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload())
            ->json('data.id');
        $this->actingAs($this->admin())->postJson("/api/messages/{$id}/send")->assertOk();

        $inbox = $this->actingAs($reader)->getJson('/api/messages/inbox')->assertOk();
        $inbox->assertJsonCount(1, 'data');
        $deliveryId = $inbox->json('data.0.id');

        $this->actingAs($reader)->postJson("/api/messages/deliveries/{$deliveryId}/dismiss")->assertNoContent();
        $this->actingAs($reader)->getJson('/api/messages/inbox')->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * 🔴 Putting a notice away takes it off their screen (owner, 2026-09-18:
     * *„klik na X brise poruku iz njegovog inboxa"*).
     *
     * 🪤 And the delivery row is NOT deleted. `dismissed_at` is stamped on it,
     * so what the administration sent to whom stays whole — a message leaves
     * somebody's view, never the record.
     */
    public function test_a_notice_put_away_leaves_the_inbox_and_stays_on_the_record(): void
    {
        $reader = $this->coordinator('reader@soahtc.test');

        $id = $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload())
            ->json('data.id');
        $this->actingAs($this->admin())->postJson("/api/messages/{$id}/send")->assertOk();

        $deliveryId = $this->actingAs($reader)->getJson('/api/messages/inbox')->json('data.0.id');
        $this->actingAs($reader)->postJson("/api/messages/deliveries/{$deliveryId}/dismiss")->assertNoContent();

        $this->actingAs($reader)->getJson('/api/messages/inbox')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.unread', 0);

        $delivery = MessageDelivery::findOrFail($deliveryId);
        $this->assertNotNull($delivery->dismissed_at, 'the record of the send has to survive');
    }

    /**
     * A tap reads it (owner, 2026-09-18: *„tap na poruku ce je uciniti
     * procitanom"*), which silences the bell and costs nothing.
     *
     * 🔴 Reported from a phone: a push arrived, the tap opened the inbox, and the
     * message *„je ostala ne procitana"*. It had to — nothing in the application
     * could record that anybody had read anything. The bell counted what had not
     * been DISMISSED, so the only way to put it out was ×, and × is final.
     */
    public function test_a_tap_reads_a_notice_and_the_bell_stops_counting_it(): void
    {
        $reader = $this->coordinator('reader@soahtc.test');
        $deliveryId = $this->sendOneTo($reader);

        $this->actingAs($reader)->getJson('/api/messages/inbox')
            ->assertJsonPath('data.0.read', false)
            ->assertJsonPath('meta.unread', 1);

        $this->actingAs($reader)->postJson("/api/messages/deliveries/{$deliveryId}/read")->assertNoContent();

        $this->actingAs($reader)->getJson('/api/messages/inbox')
            ->assertOk()
            // 🔴 Still there. Reading is not throwing away.
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.read', true)
            ->assertJsonPath('meta.unread', 0);

        $this->assertNull(
            MessageDelivery::findOrFail($deliveryId)->dismissed_at,
            'reading a notice must not put it away — × is the only thing that does, and × cannot be undone',
        );
    }

    /**
     * 🪤 Stamped once. A second tap on a row already read would move the time
     * forward, and then the only question the column can be asked — *when* did
     * this reach them — would answer with the last idle tap.
     */
    public function test_reading_a_notice_twice_does_not_move_the_time(): void
    {
        $reader = $this->coordinator('reader@soahtc.test');
        $deliveryId = $this->sendOneTo($reader);

        $this->actingAs($reader)->postJson("/api/messages/deliveries/{$deliveryId}/read")->assertNoContent();
        $first = MessageDelivery::findOrFail($deliveryId)->read_at;

        $this->travel(2)->hours();
        $this->actingAs($reader)->postJson("/api/messages/deliveries/{$deliveryId}/read")->assertNoContent();

        $this->assertTrue(
            $first->equalTo(MessageDelivery::findOrFail($deliveryId)->read_at),
            'the time a notice was read is the FIRST time, not the last tap on it',
        );
    }

    /**
     * 🔴 Putting a notice away unread does not record that it was read. The two
     * are different acts, and a row claiming to have been read because somebody
     * swiped it off a screen would be the record telling the administration
     * something that did not happen.
     */
    public function test_a_notice_swiped_away_unread_is_not_recorded_as_read(): void
    {
        $reader = $this->coordinator('reader@soahtc.test');
        $deliveryId = $this->sendOneTo($reader);

        $this->actingAs($reader)->postJson("/api/messages/deliveries/{$deliveryId}/dismiss")->assertNoContent();

        $delivery = MessageDelivery::findOrFail($deliveryId);
        $this->assertNull($delivery->read_at, 'swiping is not reading');
        $this->assertNotNull($delivery->dismissed_at);

        // And it is out of the count either way: the bell asks about the inbox.
        $this->actingAs($reader)->getJson('/api/messages/inbox')->assertJsonPath('meta.unread', 0);
    }

    /**
     * 🔴 The bell counts what has NOT BEEN READ, which stopped being the size of
     * the inbox on 2026-09-18. A read notice keeps its place on the screen and
     * its place on the record; it only stops being counted.
     */
    public function test_the_bell_counts_the_unread_and_not_the_inbox(): void
    {
        $reader = $this->coordinator('reader@soahtc.test');
        $first = $this->sendOneTo($reader, 'First');
        $this->sendOneTo($reader, 'Second');

        $this->actingAs($reader)->postJson("/api/messages/deliveries/{$first}/read")->assertNoContent();

        $this->actingAs($reader)->getJson('/api/messages/inbox')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.unread', 1);
    }

    /**
     * One person's own notice, as the dismiss beside it is.
     *
     * 🪤 Both halves, and the order matters. A 404 for the wrong person is also
     * what an application with NO such endpoint answers, so the refusal on its
     * own proves nothing — it passed against the code that had never heard of
     * reading a notice. The 204 for the right person is what makes the 404 mean
     * "not yours" rather than "not a thing".
     */
    public function test_a_coordinator_cannot_read_somebody_elses_notice(): void
    {
        $mine = $this->coordinator('mine@soahtc.test');
        $deliveryId = $this->sendOneTo($mine);

        $this->actingAs($this->coordinator('other@soahtc.test'))
            ->postJson("/api/messages/deliveries/{$deliveryId}/read")
            ->assertNotFound();

        $this->assertNull(MessageDelivery::findOrFail($deliveryId)->read_at);

        $this->actingAs($mine)
            ->postJson("/api/messages/deliveries/{$deliveryId}/read")
            ->assertNoContent();

        $this->assertNotNull(MessageDelivery::findOrFail($deliveryId)->read_at);
    }

    /** One message, sent, and the id of the delivery it left for this person. */
    private function sendOneTo(User $reader, string $subject = 'Notice'): int
    {
        $id = $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload(['subject' => $subject]))
            ->json('data.id');
        $this->actingAs($this->admin())->postJson("/api/messages/{$id}/send")->assertOk();

        return (int) MessageDelivery::query()
            ->where('message_id', $id)
            ->where('user_id', $reader->id)
            ->where('channel', 'app')
            ->value('id');
    }

    /**
     * Ten at a time, and the screen is told whether there is more (owner,
     * 2026-09-18: *„prikaz 10 poslednjih poruka pa load more"*).
     *
     * 🪤 The cursor is the OLDEST id on screen, not a page number: a notice
     * arriving between two requests would shift a numbered page by one and the
     * reader would be shown a row twice while another went missing.
     */
    public function test_the_inbox_comes_ten_at_a_time(): void
    {
        $reader = $this->coordinator('reader@soahtc.test');

        for ($i = 0; $i < 12; $i++) {
            $id = $this->actingAs($this->admin())
                ->postJson('/api/messages', $this->payload(['subject' => 'Notice '.$i]))
                ->json('data.id');
            $this->actingAs($this->admin())->postJson("/api/messages/{$id}/send")->assertOk();
        }

        $first = $this->actingAs($reader)->getJson('/api/messages/inbox')->assertOk();
        $first->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.unread', 12);

        $rows = $first->json('data');
        $oldest = end($rows)['id'];

        $next = $this->actingAs($reader)->getJson('/api/messages/inbox?before='.$oldest)->assertOk();
        $next->assertJsonCount(2, 'data')->assertJsonPath('meta.has_more', false);

        // 🔴 And the second page does not repeat the first.
        $this->assertSame(
            [],
            array_intersect(array_column($rows, 'id'), array_column($next->json('data'), 'id')),
        );
    }

    /** The inbox is one person's own. */
    public function test_the_inbox_never_carries_somebody_elses_notice(): void
    {
        $mine = $this->coordinator('mine@soahtc.test');
        $this->coordinator('theirs@soahtc.test');

        $id = $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload())
            ->json('data.id');
        $this->actingAs($this->admin())->postJson("/api/messages/{$id}/send")->assertOk();

        // Two people were sent it; each sees one row, their own.
        $rows = $this->actingAs($mine)->getJson('/api/messages/inbox')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame(
            $mine->id,
            (int) MessageDelivery::findOrFail($rows[0]['id'])->user_id,
        );
    }

    /**
     * 🔴 The MAIL channel stays out of it. A row there says an address was handed
     * something, which is a fact about a mail server rather than about this
     * person — and in a list headed "your notices" it reads as one more thing to
     * act on. Their mail is in their mail.
     */
    public function test_the_inbox_does_not_list_what_went_out_by_mail(): void
    {
        $reader = $this->coordinator('reader@soahtc.test');

        $id = $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload(['channels' => ['app', 'mail']]))
            ->json('data.id');
        $this->actingAs($this->admin())->postJson("/api/messages/{$id}/send")->assertOk();

        $this->assertSame(
            2,
            MessageDelivery::where('user_id', $reader->id)->count(),
            'the fixture has to send both channels for this to be testing anything',
        );

        $this->actingAs($reader)->getJson('/api/messages/inbox')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * The notification channel is one the administration can pick, and picking
     * it owes a delivery like any other.
     *
     * 🪤 It was missing from the compose screen until 2026-09-18 — not removed,
     * never added: the channel did not exist, and a box nobody can tick is a
     * promise the screen cannot keep. This is the half of that the suite can
     * hold: the API has always accepted it, so nothing but the screen was
     * stopping a message from going out by push.
     */
    public function test_a_message_can_be_sent_by_notification(): void
    {
        $reader = $this->coordinator('reader@soahtc.test');
        config(['push.vapid.public' => 'a-public-key', 'push.vapid.private' => 'a-private-key']);

        $id = $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload(['channels' => ['app', 'push']]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin())->postJson("/api/messages/{$id}/send")->assertOk();

        // The in-app row is the delivery; the push row is owed and waits for the
        // scheduler, exactly as mail does.
        $this->assertDatabaseHas('message_deliveries', [
            'message_id' => $id,
            'user_id' => $reader->id,
            'channel' => 'app',
            'status' => MessageDelivery::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('message_deliveries', [
            'message_id' => $id,
            'user_id' => $reader->id,
            'channel' => 'push',
            'status' => MessageDelivery::STATUS_PENDING,
        ]);
    }

    public function test_a_coordinator_cannot_dismiss_somebody_elses_notice(): void
    {
        $mine = $this->coordinator('mine@soahtc.test');
        $theirs = $this->coordinator('theirs@soahtc.test');

        $id = $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload())
            ->json('data.id');
        $this->actingAs($this->admin())->postJson("/api/messages/{$id}/send")->assertOk();

        $delivery = MessageDelivery::where('user_id', $theirs->id)->where('channel', 'app')->firstOrFail();

        $this->actingAs($mine)
            ->postJson("/api/messages/deliveries/{$delivery->id}/dismiss")
            ->assertNotFound();
    }

    /**
     * 🪤 The list is read by the SPA as `data` + `meta`. Laravel's own paginator
     * shape puts the totals at the top level, and a page returned that way
     * reaches the screen as "could not load" with nothing in the log.
     */
    public function test_the_list_comes_back_in_the_shape_every_other_list_uses(): void
    {
        $this->actingAs($this->admin())->postJson('/api/messages', $this->payload())->assertCreated();

        $this->actingAs($this->admin())
            ->getJson('/api/messages')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonStructure(['meta' => ['current_page', 'last_page', 'total']]);
    }

    /**
     * 🚤 A mail body is Markdown, and Markdown reads one newline as a
     * space. Two lines typed in the box reached the inbox as one sentence,
     * while the same text in the app stood on two - one message, two readings.
     */
    public function test_the_mail_keeps_the_line_breaks_that_were_typed(): void
    {
        $message = new Message([
            'subject' => 'Two lines',
            'body' => "First line\nSecond line\n\nA new paragraph",
        ]);

        $html = (new CoordinatorMessage($message, 'Jelena'))->render();

        $this->assertStringContainsString('First line<br', $html);
        // A blank line was already a paragraph and stays one.
        $this->assertStringContainsString('A new paragraph', $html);
    }

    /**
     * 🔴 The body is typed by a person in the administration, and the
     * mail is the one place that text leaves the system.
     */
    public function test_markup_typed_into_the_body_does_not_reach_the_inbox_as_markup(): void
    {
        $message = new Message([
            'subject' => 'Careful',
            'body' => '<script>alert(1)</script> and <b>bold</b>',
        ]);

        $html = (new CoordinatorMessage($message, 'Jelena'))->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;bold', $html);
    }

    public function test_a_coordinator_cannot_reach_the_administration_screen(): void
    {
        $reader = $this->coordinator('reader@soahtc.test');

        $this->actingAs($reader)->getJson('/api/messages')->assertForbidden();
        $this->actingAs($reader)->postJson('/api/messages', $this->payload())->assertForbidden();
    }
}
