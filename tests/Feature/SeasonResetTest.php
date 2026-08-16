<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuestionAnswer;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Registration;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `season:reset` one-pass cleanup: wipes the season-transactional chain, deletes
 * school-coordinator accounts, deactivates every other non-admin account and all
 * schools, and leaves content/config/admins untouched.
 */
class SeasonResetTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

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

    /** Build a full content chain + a completed attempt (registration + attempt + answer + session). */
    private function completedAttempt(): void
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $quiz = Quiz::create(['title' => 'Q', 'quiz_type' => 'sample', 'status' => 'active']);
        $quiz->levels()->attach($level->id);
        $exam = Exam::create(['title' => 'E', 'status' => 'active']);
        $exam->levels()->attach($level->id);
        $quiz->exams()->attach($exam->id, ['position' => 1]);
        $test = Test::create(['title' => 'T', 'duration' => 30, 'status' => 'active']);
        $test->levels()->attach($level->id);
        $exam->tests()->attach($test->id, ['position' => 1]);

        $question = Question::create(['title' => 'MC', 'description' => 'Pick', 'question_type' => 'multiple_choice', 'points' => 2, 'status' => 'active']);
        $correct = QuestionAnswer::create(['question_id' => $question->id, 'text' => 'Right', 'is_correct' => true, 'position' => 1])->id;
        QuestionAnswer::create(['question_id' => $question->id, 'text' => 'Wrong', 'is_correct' => false, 'position' => 2]);
        $question->levels()->attach($level->id);
        $test->questions()->attach($question->id, ['position' => 1]);

        $school = School::firstOrFail();
        $this->seq++;
        $number = '14'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT);
        Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => $number, 'sequence' => $this->seq,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $level->id, 'name' => 'Student',
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);
        $token = $this->postJson('/api/student/identify', [
            'competitor_number' => $number, 'country_id' => $school->country_id, 'date_of_birth' => '2010-05-01',
        ])->json('token');

        $attemptId = (int) $this->withToken($token)->postJson("/api/student/tests/{$test->id}/start")->json('attempt.id');
        $this->withToken($token)->postJson("/api/student/attempts/{$attemptId}/submit", [
            'answers' => [['question_id' => $question->id, 'response' => ['selected' => [$correct]]]],
        ])->assertOk();
    }

    private function makeCoordinator(string $roleKey, string $email): User
    {
        $school = School::firstOrFail();
        $schoolIds = $roleKey === SystemRole::CountryCoordinator->value
            ? School::where('country_id', $school->country_id)->pluck('id')->all()
            : [$school->id];

        $this->actingAs($this->admin())
            ->postJson('/api/coordinators', [
                'name' => ucfirst($roleKey),
                'email' => $email,
                'password' => 'secret-password',
                'country_id' => $school->country_id,
                'role_id' => $this->roleId($roleKey),
                'school_ids' => $schoolIds,
            ])
            ->assertCreated();

        return User::where('email', $email)->firstOrFail();
    }

    public function test_reset_wipes_transactions_deletes_school_coordinators_and_deactivates_the_rest(): void
    {
        $this->completedAttempt();
        $schoolCoord = $this->makeCoordinator(SystemRole::SchoolCoordinator->value, 'school@soahtc.test');
        $countryCoord = $this->makeCoordinator(SystemRole::CountryCoordinator->value, 'country@soahtc.test');
        $schoolAssignmentId = DB::table('season_user_assignments')->where('user_id', $schoolCoord->id)->value('id');
        $countryAssignmentId = DB::table('season_user_assignments')->where('user_id', $countryCoord->id)->value('id');
        DB::table('publication_batches')->insert([
            'scope_type' => 'exam', 'scope_id' => 1, 'action' => 'publish',
            'attempts_count' => 1, 'published_by' => $this->admin()->id, 'created_at' => now(),
        ]);
        DB::table('audit_logs')->insert([
            'action' => 'results.publish', 'actor_label' => 'Dev Admin', 'created_at' => now(),
        ]);

        // Preconditions: data exists, everyone active, schools active.
        $this->assertGreaterThan(0, Registration::count());
        $this->assertGreaterThan(0, DB::table('attempts')->count());
        $activeSchoolsBefore = School::where('status', 'active')->count();
        $this->assertGreaterThan(0, $activeSchoolsBefore);

        $this->artisan('season:reset', ['--force' => true])->assertExitCode(0);

        // Transactional chain is empty.
        foreach (['registrations', 'attempts', 'attempt_answers', 'attempt_resets', 'grade_revisions', 'student_sessions', 'student_session_quiz', 'publication_batches', 'audit_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} should be empty");
        }

        // School coordinator account is deleted, and its school scope cascades away.
        $this->assertDatabaseMissing('users', ['id' => $schoolCoord->id]);
        $this->assertDatabaseMissing('season_user_assignments', ['user_id' => $schoolCoord->id]);
        $this->assertDatabaseMissing('assignment_schools', ['season_user_assignment_id' => $schoolAssignmentId]);

        // Country coordinator is kept (deactivated) with its assignment + scope intact; admin stays active.
        $this->assertSame('inactive', $countryCoord->fresh()->status);
        $this->assertDatabaseHas('season_user_assignments', ['id' => $countryAssignmentId, 'user_id' => $countryCoord->id]);
        $this->assertGreaterThan(0, DB::table('assignment_schools')->where('season_user_assignment_id', $countryAssignmentId)->count());
        $this->assertSame('active', $this->admin()->fresh()->status);

        // Content library survives; all schools are deactivated (none deleted).
        $this->assertGreaterThan(0, Quiz::count());
        $this->assertGreaterThan(0, Test::count());
        $this->assertSame(0, School::where('status', 'active')->count());
        $this->assertSame($activeSchoolsBefore, School::where('status', 'inactive')->count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->completedAttempt();
        $countryCoord = $this->makeCoordinator(SystemRole::CountryCoordinator->value, 'country@soahtc.test');
        $regsBefore = Registration::count();
        $activeSchools = School::where('status', 'active')->count();

        $this->artisan('season:reset', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame($regsBefore, Registration::count());
        $this->assertDatabaseHas('users', ['id' => $countryCoord->id, 'status' => 'active']);
        $this->assertSame($activeSchools, School::where('status', 'active')->count());
    }
}
