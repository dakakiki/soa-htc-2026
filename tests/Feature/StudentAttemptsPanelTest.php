<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamRound;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\Registration;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The student page's exams panel: every exam one competitor has sat, split into
 * contest and practice, with the row the administrator can take back so the
 * child sits it again.
 *
 * The split is the ROUND's `is_sample` (ADR-0084) — the test proves that by
 * putting the two tests in rounds that disagree with nothing else, so a future
 * copy of the rule keyed on the quiz type or on `attempts.is_practice` fails
 * here rather than quietly in a report.
 */
class StudentAttemptsPanelTest extends TestCase
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

    /**
     * A quiz → exam → test chain. `is_sample` on the ROUND is what decides the
     * side; a contest chain gets an exam with no round at all, which is how the
     * live data spells it.
     *
     * @return array{quiz: Quiz, exam: Exam, test: Test}
     */
    private function content(string $label, bool $sample): array
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();

        $quiz = Quiz::create(['title' => $label.'Q', 'quiz_type' => 'competition', 'status' => 'active']);
        $quiz->levels()->attach($level->id);

        $exam = Exam::create([
            'title' => $label.'E',
            'status' => 'active',
            'exam_round_id' => $sample
                ? ExamRound::create(['name' => $label.'R', 'active' => true, 'sort_order' => 90, 'is_sample' => true])->id
                : null,
        ]);
        $exam->levels()->attach($level->id);
        $quiz->exams()->attach($exam->id, ['position' => 1]);

        $test = Test::create(['title' => $label.'T', 'duration' => 30, 'status' => 'active']);
        $test->levels()->attach($level->id);
        $exam->tests()->attach($test->id, ['position' => 1]);

        return ['quiz' => $quiz, 'exam' => $exam, 'test' => $test];
    }

    private function student(?School $school = null): Registration
    {
        $school ??= School::firstOrFail();
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $this->seq++;

        return Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'sequence' => $this->seq,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $level->id, 'name' => 'Student '.$this->seq,
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);
    }

    private function attempt(Registration $reg, array $c, string $status = 'completed'): Attempt
    {
        return Attempt::create([
            'registration_id' => $reg->id, 'quiz_id' => $c['quiz']->id, 'test_id' => $c['test']->id,
            'status' => $status, 'score' => 5, 'max_score' => 10,
            'grading_status' => $status === 'completed' ? 'auto_graded' : null,
            'started_at' => now(), 'expires_at' => now(),
            'submitted_at' => $status === 'completed' ? now() : null, 'channel' => 'web',
        ]);
    }

    /** Any round id, for the Layer B rows the tests plant directly. */
    private function anyRoundId(): int
    {
        return (int) (ExamRound::query()->value('id')
            ?? ExamRound::create(['name' => 'LayerB', 'active' => true, 'sort_order' => 99])->id);
    }

    public function test_it_splits_the_competitors_exams_into_contest_and_practice(): void
    {
        $reg = $this->student();
        $this->attempt($reg, $this->content('Contest', false));
        $this->attempt($reg, $this->content('Practice', true));

        $res = $this->actingAs($this->admin())->getJson("/api/registrations/{$reg->id}/attempts");

        $res->assertOk()
            ->assertJsonCount(1, 'data.competition')
            ->assertJsonCount(1, 'data.sample')
            ->assertJsonPath('data.competition.0.quiz_title', 'ContestQ')
            // The attempt records quiz and test; the exam between them is recovered
            // from the two pivots, so it is worth asserting rather than assuming.
            ->assertJsonPath('data.competition.0.exam_title', 'ContestE')
            ->assertJsonPath('data.competition.0.test_title', 'ContestT')
            ->assertJsonPath('data.competition.0.is_sample', false)
            ->assertJsonPath('data.sample.0.quiz_title', 'PracticeQ')
            ->assertJsonPath('data.sample.0.exam_title', 'PracticeE')
            ->assertJsonPath('data.sample.0.test_title', 'PracticeT')
            ->assertJsonPath('data.sample.0.is_sample', true);
    }

    public function test_it_leaves_out_attempts_that_were_already_reset(): void
    {
        $reg = $this->student();
        $this->attempt($reg, $this->content('Gone', false), 'void');

        $this->actingAs($this->admin())
            ->getJson("/api/registrations/{$reg->id}/attempts")
            ->assertOk()
            ->assertJsonCount(0, 'data.competition')
            ->assertJsonCount(0, 'data.sample');
    }

    public function test_deleting_a_result_removes_the_attempt_its_answers_and_the_published_mark(): void
    {
        $reg = $this->student();
        $c = $this->content('Retake', false);
        $attempt = $this->attempt($reg, $c);

        // An answer, so the cascade has something to take with it.
        $question = Question::create([
            'title' => 'Q', 'description' => 'Pick', 'question_type' => 'multiple_choice',
            'points' => 2, 'status' => 'active',
        ]);
        $answerId = DB::table('attempt_answers')->insertGetId([
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The published mark in Layer B. It has NO foreign key to `attempts`, so
        // nothing in the database removes it on its own — this row is the whole
        // reason the endpoint does more than $attempt->delete().
        DB::table('registration_results')->insert([
            'registration_id' => $reg->id,
            'test_id' => $c['test']->id,
            'exam_round_id' => $this->anyRoundId(),
            'season_id' => Season::where('round_number', 14)->value('id'),
            'score' => 5, 'max_score' => 10,
            'source' => 'attempt', 'published_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/results/attempts/{$attempt->id}")
            ->assertNoContent();

        // 1) the attempt is gone outright — not voided, gone
        $this->assertDatabaseMissing('attempts', ['id' => $attempt->id]);
        $this->assertDatabaseCount('attempt_resets', 0);

        // 2) its answers went with it by cascade
        $this->assertDatabaseMissing('attempt_answers', ['id' => $answerId]);

        // 3) and so did the published mark, which no cascade would have touched
        $this->assertDatabaseMissing('registration_results', [
            'registration_id' => $reg->id, 'test_id' => $c['test']->id,
        ]);

        // The panel is empty, and the one-attempt slot is free again.
        $this->actingAs($this->admin())
            ->getJson("/api/registrations/{$reg->id}/attempts")
            ->assertOk()
            ->assertJsonCount(0, 'data.competition');

        $this->assertSame(0, Attempt::query()
            ->where('registration_id', $reg->id)->where('test_id', $c['test']->id)
            ->count());
    }

    public function test_deleting_a_result_clears_an_imported_mark_for_the_same_test(): void
    {
        $reg = $this->student();
        $c = $this->content('Imported', false);
        $attempt = $this->attempt($reg, $c);

        // Owner, 2026-09-17: nothing about that test and that child survives. An
        // offline import is the same mark to whoever reads a report, so it goes too.
        DB::table('registration_results')->insert([
            'registration_id' => $reg->id,
            'test_id' => $c['test']->id,
            'exam_round_id' => $this->anyRoundId(),
            'season_id' => Season::where('round_number', 14)->value('id'),
            'score' => 9, 'max_score' => 10,
            'source' => 'import', 'published_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/results/attempts/{$attempt->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('registration_results', [
            'registration_id' => $reg->id, 'test_id' => $c['test']->id,
        ]);
    }

    public function test_deleting_a_result_leaves_other_students_and_other_tests_alone(): void
    {
        $c = $this->content('Shared', false);
        $other = $this->content('Other', false);

        $mine = $this->student();
        $theirs = $this->student();
        $attempt = $this->attempt($mine, $c);
        $keepSameTest = $this->attempt($theirs, $c);
        $keepOtherTest = $this->attempt($mine, $other);

        $this->actingAs($this->admin())
            ->deleteJson("/api/results/attempts/{$attempt->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('attempts', ['id' => $keepSameTest->id]);
        $this->assertDatabaseHas('attempts', ['id' => $keepOtherTest->id]);
    }

    public function test_deleting_a_result_needs_the_results_permission(): void
    {
        $reg = $this->student();
        $attempt = $this->attempt($reg, $this->content('Guarded', false));

        $this->deleteJson("/api/results/attempts/{$attempt->id}")->assertUnauthorized();

        // Editing a student is not the same right as destroying their result.
        $season = Season::where('round_number', 14)->firstOrFail();
        $role = Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail();
        $coordinator = User::factory()->create(['can_student_edit' => true]);
        $assignment = SeasonUserAssignment::create([
            'season_id' => $season->id, 'user_id' => $coordinator->id,
            'role_id' => $role->id, 'status' => 'active',
        ]);
        $assignment->schools()->sync([School::firstOrFail()->id]);

        $this->actingAs($coordinator)
            ->deleteJson("/api/results/attempts/{$attempt->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('attempts', ['id' => $attempt->id]);
    }

    public function test_it_refuses_a_caller_who_may_not_see_the_student(): void
    {
        $reg = $this->student();

        $this->getJson("/api/registrations/{$reg->id}/attempts")->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/registrations/{$reg->id}/attempts")
            ->assertForbidden();
    }

    public function test_a_coordinator_cannot_read_a_student_outside_their_schools(): void
    {
        $mine = School::firstOrFail();
        $theirs = School::where('id', '!=', $mine->id)->firstOrFail();

        $season = Season::where('round_number', 14)->firstOrFail();
        $role = Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail();
        $coordinator = User::factory()->create(['can_student_edit' => true]);
        $assignment = SeasonUserAssignment::create([
            'season_id' => $season->id, 'user_id' => $coordinator->id,
            'role_id' => $role->id, 'status' => 'active',
        ]);
        $assignment->schools()->sync([$mine->id]);

        $outside = $this->student($theirs);
        $ours = $this->student($mine);

        $this->actingAs($coordinator)
            ->getJson("/api/registrations/{$outside->id}/attempts")
            ->assertForbidden();

        // ...and the same call inside their own schools still works, so the test
        // proves a boundary rather than a coordinator who can do nothing.
        $this->actingAs($coordinator)
            ->getJson("/api/registrations/{$ours->id}/attempts")
            ->assertOk();
    }
}
