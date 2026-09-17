<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Competition\Models\Registration;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What staff do to a competitor's registration is written to the trail
 * (ADR-0110).
 *
 * 🔴 The owner's complaint was that accounts had been used for things nobody
 * could afterwards pin on anybody. Sign-ins said somebody was in; these rows say
 * what they did once inside.
 */
class StudentTrailTest extends TestCase
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

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'school_id' => School::firstOrFail()->id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Trail Student',
            'grade' => 7,
            'date_of_birth' => '2010-05-01',
        ];
    }

    public function test_creating_a_student_is_written_down(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/registrations', $this->payload())
            ->assertCreated();

        $row = AuditLog::where('action', 'student.created')->sole();

        $this->assertSame($this->admin()->id, $row->actor_id);
        $this->assertSame('Trail Student', $row->after['name']);
        $this->assertNotNull($row->after['competitor_number']);
        // Text, not ids: an id points at a row that may itself be gone.
        $this->assertSame(School::firstOrFail()->name, $row->after['venue']);
    }

    public function test_editing_a_student_keeps_both_sides_of_the_change(): void
    {
        $id = $this->actingAs($this->admin())
            ->postJson('/api/registrations', $this->payload())->json('data.id');

        $this->actingAs($this->admin())
            ->putJson("/api/registrations/{$id}", array_merge($this->payload(), ['name' => 'Renamed Student']))
            ->assertOk();

        $row = AuditLog::where('action', 'student.updated')->sole();

        $this->assertSame('Trail Student', $row->before['name']);
        $this->assertSame('Renamed Student', $row->after['name']);
    }

    /**
     * 🔴 The snapshot is taken BEFORE the delete. Afterwards there is nothing left
     * to read, and "who lost their place" is the whole question a deletion raises.
     */
    public function test_deleting_a_student_keeps_what_was_deleted(): void
    {
        $id = $this->actingAs($this->admin())
            ->postJson('/api/registrations', $this->payload())->json('data.id');
        $number = Registration::findOrFail($id)->competitor_number;

        $this->actingAs($this->admin())->deleteJson("/api/registrations/{$id}")->assertNoContent();

        $row = AuditLog::where('action', 'student.deleted')->sole();

        $this->assertSame('Trail Student', $row->before['name']);
        $this->assertSame($number, $row->before['competitor_number']);
        $this->assertDatabaseMissing('registrations', ['id' => $id]);
    }

    /**
     * 🔴 Owner, 2026-09-17: "kod akcija export import i sl. necemo beleziti sta je
     * exportovao. samo da je uradio tu akciju". The count and the filters say
     * whether the reach was reasonable; the rows themselves never go in.
     */
    public function test_an_export_records_the_act_and_never_the_contents(): void
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $school = School::firstOrFail();
        Registration::create([
            'season_id' => $season->id, 'competitor_number' => '14555001', 'sequence' => 555001,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->value('id'),
            'name' => 'Secret Child', 'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->get('/api/registrations/export?country_id='.$school->country_id)
            ->assertOk();

        $row = AuditLog::where('action', 'students.exported')->sole();

        $this->assertGreaterThan(0, $row->after['rows']);
        $this->assertSame($school->country_id, (int) $row->after['filters']['country_id']);

        // Nobody's name reaches a table kept for years.
        $this->assertStringNotContainsString('Secret Child', json_encode($row->getAttributes()));
        $this->assertStringNotContainsString('14555001', json_encode($row->getAttributes()));
    }

    /** A competitor sitting an exam is still not the trail's business. */
    public function test_the_competition_itself_stays_out(): void
    {
        $before = AuditLog::count();

        $this->postJson('/api/identify', [
            'competitor_number' => '14000001',
            'date_of_birth' => '2010-01-01',
            'country_id' => 1,
        ]);

        $this->assertSame($before, AuditLog::count());
    }
}
