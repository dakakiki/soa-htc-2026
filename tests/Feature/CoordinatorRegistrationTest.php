<?php

namespace Tests\Feature;

use App\Domain\Cms\Models\Media;
use App\Domain\Cms\Support\LayoutZones;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Organization\Enums\CoordinatorRegistrationStatus;
use App\Domain\Organization\Models\CoordinatorRegistration;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Support\SeasonContext;
use App\Mail\CoordinatorRegistrationApproved;
use App\Mail\CoordinatorRegistrationDeclined;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Public coordinator registration and the queue that decides it (ADR-0053).
 */
class CoordinatorRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@soahtc.test')->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Marija Petrović',
            'email' => 'marija@skola.rs',
            'phone' => '+381 11 555 000',
            'address' => 'Kneza Miloša 1',
            'city' => 'Belgrade',
            'country_id' => Country::query()->value('id'),
            'password' => 'a-good-password',
            'password_confirmation' => 'a-good-password',
            'document' => UploadedFile::fake()->create('venue-approval.pdf', 200, 'application/pdf'),
        ], $overrides);
    }

    private function submit(array $overrides = []): TestResponse
    {
        return $this->post('/api/public/coordinator-registrations', $this->payload($overrides));
    }

    public function test_anyone_may_register_and_no_account_is_created(): void
    {
        $this->submit()->assertCreated()->assertJsonPath('data.received', true);

        $registration = CoordinatorRegistration::query()->firstOrFail();

        $this->assertSame('marija@skola.rs', $registration->email);
        $this->assertSame(CoordinatorRegistrationStatus::Pending, $registration->status);
        // The whole point: an application is not an account. Legacy wrote the
        // applicant into `users` with `active = 0` and took the address with it.
        $this->assertDatabaseMissing('users', ['email' => 'marija@skola.rs']);
    }

    public function test_the_password_is_hashed_on_the_way_in(): void
    {
        $this->submit()->assertCreated();

        $stored = CoordinatorRegistration::query()->value('password');

        $this->assertNotSame('a-good-password', $stored);
        $this->assertTrue(Hash::check('a-good-password', $stored));
    }

    public function test_the_document_goes_to_the_private_disk(): void
    {
        $this->submit()->assertCreated();

        $registration = CoordinatorRegistration::query()->firstOrFail();

        $this->assertStringStartsWith('coordinator-approvals/', $registration->document_path);
        Storage::disk('local')->assertExists($registration->document_path);
        $this->assertSame('venue-approval.pdf', $registration->document_name);
    }

    public function test_the_document_is_required_and_must_be_a_document(): void
    {
        $this->postJson('/api/public/coordinator-registrations', array_diff_key($this->payload(), ['document' => null]))
            ->assertJsonValidationErrors('document');

        $this->submit(['document' => UploadedFile::fake()->image('photo.jpg')])
            ->assertJsonValidationErrors('document');
    }

    public function test_the_password_floor_is_eight_and_it_must_be_confirmed(): void
    {
        // Legacy asked for six; the approved design tells the applicant eight.
        $this->submit(['password' => 'short12', 'password_confirmation' => 'short12'])
            ->assertJsonValidationErrors('password');

        $this->submit(['password_confirmation' => 'something-else'])
            ->assertJsonValidationErrors('password');
    }

    public function test_an_address_that_already_has_an_account_is_refused(): void
    {
        $this->submit(['email' => $this->admin()->email])->assertJsonValidationErrors('email');

        $this->assertSame(0, CoordinatorRegistration::query()->count());
    }

    public function test_a_second_pending_application_from_the_same_address_is_refused(): void
    {
        $this->submit()->assertCreated();
        $this->submit()->assertJsonValidationErrors('email');

        $this->assertSame(1, CoordinatorRegistration::query()->count());
    }

    public function test_a_declined_applicant_may_apply_again(): void
    {
        $this->submit()->assertCreated();
        $registration = CoordinatorRegistration::query()->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson("/api/coordinator-registrations/{$registration->id}/decline", ['reason' => 'Wrong document'])
            ->assertOk();

        $this->submit()->assertCreated();
        $this->assertSame(2, CoordinatorRegistration::query()->count());
    }

    public function test_approval_opens_an_account_with_the_school_coordinator_role(): void
    {
        $this->submit()->assertCreated();
        $registration = CoordinatorRegistration::query()->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson("/api/coordinator-registrations/{$registration->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $user = User::query()->where('email', 'marija@skola.rs')->firstOrFail();

        $this->assertSame('active', $user->status);
        $this->assertSame('Belgrade', $user->city);
        // The password the applicant chose carries over untouched — the `hashed`
        // cast recognises a hash and does not hash it a second time.
        $this->assertTrue(Hash::check('a-good-password', $user->password));

        $this->assertDatabaseHas('season_user_assignments', [
            'user_id' => $user->id,
            'season_id' => SeasonContext::active()->id,
            'status' => 'active',
        ]);
        $this->assertSame(
            SystemRole::SchoolCoordinator->value,
            $user->seasonAssignments()->with('role')->first()->role->key,
        );
    }

    public function test_approval_attaches_no_venue(): void
    {
        // The owner's rule (2026-08-26), and legacy's `school_hub_id = 0`: the
        // account opens, an administrator attaches the venue afterwards.
        $this->submit()->assertCreated();
        $registration = CoordinatorRegistration::query()->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson("/api/coordinator-registrations/{$registration->id}/approve")
            ->assertOk();

        $assignment = User::query()->where('email', 'marija@skola.rs')->firstOrFail()
            ->seasonAssignments()->firstOrFail();

        $this->assertSame(0, $assignment->schools()->count());
    }

    public function test_both_decisions_mail_the_applicant_and_nobody_else(): void
    {
        $this->submit()->assertCreated();
        $first = CoordinatorRegistration::query()->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson("/api/coordinator-registrations/{$first->id}/approve")
            ->assertOk();

        // 🪤 The legacy app mailed venue@hippo-thecontest.org — the organisation,
        // never the coordinator. This is the correction, and it is worth a test.
        Mail::assertSent(CoordinatorRegistrationApproved::class, fn ($mail): bool => $mail->hasTo('marija@skola.rs'));

        $this->submit(['email' => 'other@skola.rs'])->assertCreated();
        $second = CoordinatorRegistration::query()->where('email', 'other@skola.rs')->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson("/api/coordinator-registrations/{$second->id}/decline", ['reason' => 'Not a venue'])
            ->assertOk();

        Mail::assertSent(CoordinatorRegistrationDeclined::class, fn ($mail): bool => $mail->hasTo('other@skola.rs'));
    }

    public function test_a_decided_registration_cannot_be_decided_again(): void
    {
        $this->submit()->assertCreated();
        $registration = CoordinatorRegistration::query()->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson("/api/coordinator-registrations/{$registration->id}/approve")
            ->assertOk();

        $this->actingAs($this->admin())
            ->postJson("/api/coordinator-registrations/{$registration->id}/approve")
            ->assertJsonValidationErrors('registration');

        $this->assertSame(1, User::query()->where('email', 'marija@skola.rs')->count());
    }

    public function test_the_decline_reason_never_reaches_the_applicant(): void
    {
        $this->submit()->assertCreated();
        $registration = CoordinatorRegistration::query()->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson("/api/coordinator-registrations/{$registration->id}/decline", ['reason' => 'Signature looks forged'])
            ->assertOk();

        Mail::assertSent(CoordinatorRegistrationDeclined::class, function ($mail): bool {
            return ! str_contains($mail->render(), 'Signature looks forged');
        });
    }

    public function test_the_queue_and_the_document_need_the_approve_permission(): void
    {
        $this->submit()->assertCreated();
        $registration = CoordinatorRegistration::query()->firstOrFail();

        $outsider = User::factory()->create();

        $this->actingAs($outsider)->getJson('/api/coordinator-registrations')->assertForbidden();
        $this->actingAs($outsider)->get("/api/coordinator-registrations/{$registration->id}/document")->assertForbidden();
        $this->actingAs($outsider)->postJson("/api/coordinator-registrations/{$registration->id}/approve")->assertForbidden();
    }

    public function test_the_document_is_not_reachable_anonymously(): void
    {
        // Somebody's signed paperwork, sitting on the private disk. Its own test
        // because `actingAs` in the one above holds for the rest of that test.
        $this->submit()->assertCreated();
        $registration = CoordinatorRegistration::query()->firstOrFail();

        $this->getJson("/api/coordinator-registrations/{$registration->id}/document")->assertUnauthorized();
    }

    public function test_the_reviewer_can_download_the_signed_approval(): void
    {
        $this->submit()->assertCreated();
        $registration = CoordinatorRegistration::query()->firstOrFail();

        $this->actingAs($this->admin())
            ->get("/api/coordinator-registrations/{$registration->id}/document")
            ->assertOk()
            ->assertDownload('venue-approval.pdf');
    }

    public function test_waiting_registrations_come_first(): void
    {
        $this->submit(['email' => 'first@skola.rs'])->assertCreated();
        $this->submit(['email' => 'second@skola.rs'])->assertCreated();

        $first = CoordinatorRegistration::query()->where('email', 'first@skola.rs')->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson("/api/coordinator-registrations/{$first->id}/approve")
            ->assertOk();

        $this->actingAs($this->admin())
            ->getJson('/api/coordinator-registrations')
            ->assertOk()
            // The decided one drops below the one still waiting, whatever its age.
            ->assertJsonPath('data.0.email', 'second@skola.rs');
    }

    public function test_a_pending_registration_cannot_be_deleted(): void
    {
        $this->submit()->assertCreated();
        $registration = CoordinatorRegistration::query()->firstOrFail();

        $this->actingAs($this->admin())
            ->deleteJson("/api/coordinator-registrations/{$registration->id}")
            ->assertStatus(422);

        $this->assertSame(1, CoordinatorRegistration::query()->count());
    }

    public function test_deleting_a_decided_registration_takes_its_document(): void
    {
        $this->submit()->assertCreated();
        $registration = CoordinatorRegistration::query()->firstOrFail();
        $path = $registration->document_path;

        $this->actingAs($this->admin())
            ->postJson("/api/coordinator-registrations/{$registration->id}/decline", ['reason' => null])
            ->assertOk();

        $this->actingAs($this->admin())
            ->deleteJson("/api/coordinator-registrations/{$registration->id}")
            ->assertNoContent();

        Storage::disk('local')->assertMissing($path);
    }

    /**
     * The layout zones are seeded for local development only, so these two build
     * the block they are about and check the zone accepts it and reads back.
     */
    public function test_the_register_zone_carries_both_steps(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/cms/layout/'.LayoutZones::PUBLIC_REGISTER.'/blocks', [
                'type' => 'register',
                'content' => [
                    'title' => 'Register as a coordinator',
                    'lead' => '<p>Sent straight away, approved by hand.</p>',
                    'sent_title' => 'With the administrators',
                    'sent_lead' => '<p>You will get an e-mail either way.</p>',
                ],
            ])
            ->assertCreated();

        $content = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_REGISTER)
            ->assertOk()
            ->assertJsonPath('data.blocks.0.type', 'register')
            ->json('data.blocks.0.content');

        // One record, both steps — the form's words and the panel that replaces it.
        $this->assertSame('Register as a coordinator', $content['title']);
        $this->assertSame('With the administrators', $content['sent_title']);
    }

    public function test_the_approval_form_link_is_resolved_or_dropped_never_raw(): void
    {
        // 🪤 LayoutButtons resolves a payload by KEY, so the block's one button
        // has to be called `button`. Named anything else it reaches the page raw
        // — a `target` and no `href` — and the page draws a dead link.
        $this->actingAs($this->admin())
            ->postJson('/api/cms/layout/'.LayoutZones::PUBLIC_REGISTER.'/blocks', [
                'type' => 'register',
                'content' => [
                    'title' => 'Register as a coordinator',
                    'button' => [
                        'label' => 'Approval form',
                        'style' => 'link',
                        'status' => true,
                        'gate' => null,
                        'target' => ['type' => 'url', 'id' => null, 'value' => 'https://example.test/form.pdf'],
                    ],
                ],
            ])
            ->assertCreated();

        $content = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_REGISTER)
            ->assertOk()
            ->json('data.blocks.0.content');

        $this->assertSame('https://example.test/form.pdf', $content['button']['href']);
        $this->assertArrayNotHasKey('target', $content['button']);
    }

    public function test_a_downloadable_form_is_not_marked_as_leaving_the_site(): void
    {
        // 🪤 `Storage::url()` is absolute, so a file button used to come back
        // `download: true, external: true` and the page drew both marks on it.
        $media = Media::query()->create([
            'path' => 'cms/media/approval-form.pdf',
            'original_name' => 'approval-form.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/cms/layout/'.LayoutZones::PUBLIC_REGISTER.'/blocks', [
                'type' => 'register',
                'content' => [
                    'title' => 'Register as a coordinator',
                    'button' => [
                        'label' => 'Approval form',
                        'style' => 'link',
                        'status' => true,
                        'gate' => null,
                        'target' => ['type' => 'file', 'id' => $media->id, 'value' => null],
                    ],
                ],
            ])
            ->assertCreated();

        $button = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_REGISTER)
            ->assertOk()
            ->json('data.blocks.0.content.button');

        $this->assertTrue($button['download']);
        $this->assertFalse($button['external']);
        // The file's own name travels with it; the URL carries only the stored
        // random key, which makes a useless filename in somebody's downloads.
        $this->assertSame('approval-form.pdf', $button['download_name']);
    }

    public function test_an_approval_form_pointing_nowhere_is_not_offered(): void
    {
        // The seeded state: the block exists before anybody has uploaded the form.
        $this->actingAs($this->admin())
            ->postJson('/api/cms/layout/'.LayoutZones::PUBLIC_REGISTER.'/blocks', [
                'type' => 'register',
                'content' => [
                    'title' => 'Register as a coordinator',
                    'button' => [
                        'label' => 'Approval form',
                        'style' => 'link',
                        'status' => true,
                        'gate' => null,
                        'target' => ['type' => 'file', 'id' => null, 'value' => null],
                    ],
                ],
            ])
            ->assertCreated();

        $content = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_REGISTER)
            ->assertOk()
            ->json('data.blocks.0.content');

        $this->assertNull($content['button']);
    }

    public function test_the_sign_in_zone_carries_the_note_under_the_form(): void
    {
        // The screenshot the owner sent: "For registered venues only." and the
        // way to the registration screen, both dropped by the redesign.
        $this->actingAs($this->admin())
            ->postJson('/api/cms/layout/'.LayoutZones::PUBLIC_LOGIN.'/blocks', [
                'type' => 'login',
                'content' => [
                    'title' => 'Sign in',
                    'aside' => '<p>For registered venues only. <a href="/register">Register as a coordinator</a>.</p>',
                ],
            ])
            ->assertCreated();

        $content = $this->getJson('/api/public/layout/'.LayoutZones::PUBLIC_LOGIN)
            ->assertOk()
            ->json('data.blocks.0.content');

        $this->assertStringContainsString('/register', (string) $content['aside']);
    }
}
