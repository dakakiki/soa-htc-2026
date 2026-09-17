<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\CoordinatorRegistration;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The administrative registers reach the trail too (ADR-0111): venues,
 * difficulty levels and categories, coordinator accounts, and the decision on a
 * coordinator's application.
 */
class AdminRegistersTrailTest extends TestCase
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

    public function test_creating_and_deleting_a_venue_is_written_down(): void
    {
        $country = Country::firstOrFail();

        $id = $this->actingAs($this->admin())->postJson('/api/schools', [
            'name' => 'Trail Venue', 'country_id' => $country->id, 'city' => 'Somewhere',
        ])->assertCreated()->json('data.id');

        $this->assertSame('Trail Venue', AuditLog::where('action', 'venue.created')->sole()->after['name']);

        $this->actingAs($this->admin())->deleteJson("/api/schools/{$id}")->assertNoContent();

        // Before the delete, or there is nothing left to name.
        $this->assertSame('Trail Venue', AuditLog::where('action', 'venue.deleted')->sole()->before['name']);
    }

    public function test_editing_a_difficulty_level_keeps_both_sides(): void
    {
        $level = DifficultyLevel::firstOrFail();

        $this->actingAs($this->admin())->putJson("/api/difficulty-levels/{$level->id}", [
            'difficulty_category_id' => $level->difficulty_category_id,
            'name' => 'Renamed Level',
            'level_short' => $level->level_short,
            'grades' => $level->grades,
        ])->assertOk();

        $row = AuditLog::where('action', 'difficulty_level.updated')->sole();

        $this->assertSame($level->name, $row->before['name']);
        $this->assertSame('Renamed Level', $row->after['name']);
    }

    public function test_editing_a_difficulty_category_is_written_down(): void
    {
        $category = DifficultyCategory::firstOrFail();

        $this->actingAs($this->admin())->putJson("/api/difficulty-categories/{$category->id}", [
            'name' => 'Renamed Category', 'type' => $category->type,
        ])->assertOk();

        $this->assertSame('Renamed Category', AuditLog::where('action', 'difficulty_category.updated')->sole()->after['name']);
    }

    /**
     * 🔴 The gap this closed: the Users screen wrote to the trail and the
     * Coordinators screen wrote nothing, though both create and delete the same
     * `User` row. Whether an account left a trace depended on which screen was
     * used — which is worse than no trail, because the trail looked complete.
     */
    public function test_a_coordinator_account_leaves_a_trace_from_its_own_screen(): void
    {
        $school = School::firstOrFail();

        $id = $this->actingAs($this->admin())->postJson('/api/coordinators', [
            'name' => 'Trail Coordinator',
            'email' => 'trail.coordinator@example.test',
            'password' => 'secret-password',
            'country_id' => $school->country_id,
            'role_id' => Role::where('key', 'school_coordinator')->value('id'),
            'school_ids' => [$school->id],
        ])->assertCreated()->json('data.id');

        // The same action name the Users screen writes: one act, one name.
        $created = AuditLog::where('action', 'user.created')->sole();
        $this->assertSame('Trail Coordinator', $created->after['name']);

        $this->actingAs($this->admin())->deleteJson("/api/coordinators/{$id}")->assertNoContent();

        $this->assertSame('Trail Coordinator', AuditLog::where('action', 'user.deleted')->sole()->before['name']);
        // 🔴 Never the password, in a table kept for years.
        $this->assertStringNotContainsString('secret-password', json_encode(AuditLog::all()->toArray()));
    }

    /**
     * 🔴 Who approved an application, asked for by the owner on 2026-09-17.
     *
     * The row already carries `reviewed_by`, and the trail normally stays out of
     * the way of a record that exists — but a decided application can be deleted
     * outright, and `reviewed_by` goes with it. The trail is the durable half.
     */
    public function test_the_decision_on_an_application_says_who_made_it(): void
    {
        Storage::fake('local');

        $country = Country::firstOrFail();
        $application = CoordinatorRegistration::create([
            'name' => 'Applicant One',
            'email' => 'applicant.one@example.test',
            'country_id' => $country->id,
            'password' => bcrypt('whatever'),
            'document_path' => UploadedFile::fake()->create('approval.pdf', 10)->store('applications', 'local'),
            'document_name' => 'approval.pdf',
            'document_mime' => 'application/pdf',
            'document_size' => 10,
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/coordinator-registrations/{$application->id}/approve")
            ->assertOk();

        $row = AuditLog::where('action', 'coordinator_application.approved')->sole();

        $this->assertSame($this->admin()->id, $row->actor_id);
        $this->assertSame($this->admin()->name, $row->actor_label);
        $this->assertSame('Applicant One', $row->after['name']);
    }

    /** Deleting a decided application is the moment `reviewed_by` stops existing. */
    public function test_deleting_an_application_keeps_who_had_decided_it(): void
    {
        Storage::fake('local');

        $country = Country::firstOrFail();
        $application = CoordinatorRegistration::create([
            'name' => 'Applicant Two',
            'email' => 'applicant.two@example.test',
            'country_id' => $country->id,
            'password' => bcrypt('whatever'),
            'document_path' => UploadedFile::fake()->create('approval.pdf', 10)->store('applications', 'local'),
            'document_name' => 'approval.pdf',
            'document_mime' => 'application/pdf',
            'document_size' => 10,
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/coordinator-registrations/{$application->id}/decline", ['reason' => 'Not this year'])
            ->assertOk();

        $this->actingAs($this->admin())
            ->deleteJson("/api/coordinator-registrations/{$application->id}")
            ->assertNoContent();

        $declined = AuditLog::where('action', 'coordinator_application.declined')->sole();
        $this->assertSame('Not this year', $declined->reason);

        $deleted = AuditLog::where('action', 'coordinator_application.deleted')->sole();
        $this->assertSame($this->admin()->id, $deleted->before['reviewed_by']);
    }
}
