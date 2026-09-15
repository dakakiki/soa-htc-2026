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
     * Each body is required by the channel that carries it, and by nothing
     * else: a mail-only message needs no notification text, and one that only
     * goes to the app needs no HTML.
     */
    public function test_a_mail_only_message_needs_no_notification_text(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload(['channels' => ['mail'], 'body' => null]))
            ->assertCreated();

        $this->assertSame([MessageChannel::Mail->value], $response->json('data.channels'));
        $this->assertNull($response->json('data.body'));
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

    public function test_a_message_needs_a_channel(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/messages', $this->payload(['channels' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('channels');
    }

    public function test_push_is_refused_while_there_is_nowhere_to_push_to(): void
    {
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
