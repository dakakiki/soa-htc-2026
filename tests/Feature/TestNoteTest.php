<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuestionAnswer;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\Registration;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A note between the questions of a test.
 *
 * Until now the only way to put a task heading in front of a group of questions
 * was to enter it AS a question — a title with nothing under it — and the legacy
 * import brought twenty of those across (ADR-0060). A note is not a question:
 * never answered, never graded, never numbered, and not what makes a test
 * non-empty.
 *
 * 🪤 It is anchored BEFORE a question rather than placed in the same sequence.
 * The exam screen numbers questions by their index in the list it is handed
 * (ADR-0034), so a note sharing that list would eat a number.
 */
class TestNoteTest extends TestCase
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

    private function question(string $title): Question
    {
        $q = Question::create([
            'title' => $title, 'question_type' => 'multiple_choice', 'points' => 1, 'status' => 'active',
        ]);
        QuestionAnswer::create(['question_id' => $q->id, 'text' => 'Right', 'is_correct' => true, 'position' => 1]);
        QuestionAnswer::create(['question_id' => $q->id, 'text' => 'Wrong', 'is_correct' => false, 'position' => 2]);

        return $q;
    }

    public function test_a_test_is_created_with_its_notes_in_place(): void
    {
        $a = $this->question('A');
        $b = $this->question('B');

        $this->actingAs($this->admin())
            ->postJson('/api/tests', [
                'title' => 'Reading',
                'level_ids' => [DifficultyLevel::where('level_short', 'H2')->firstOrFail()->id],
                'question_ids' => [$a->id, $b->id],
                'notes' => [
                    ['before_position' => 0, 'body' => '<p>TASK 1 Read the text.</p>'],
                    ['before_position' => 1, 'body' => '<p>TASK 2 Look at the picture.</p>'],
                    ['before_position' => 2, 'body' => '<p>Well done.</p>'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.notes.0.before_position', 0)
            ->assertJsonPath('data.notes.1.before_position', 1)
            // Anchored past the last question: a closing line after everything.
            ->assertJsonPath('data.notes.2.before_position', 2)
            ->assertJsonPath('data.notes.2.body', '<p>Well done.</p>');
    }

    public function test_two_notes_in_the_same_place_keep_the_order_they_arrived_in(): void
    {
        $test = $this->makeTest();

        $this->actingAs($this->admin())
            ->putJson("/api/tests/{$test->id}", ['notes' => [
                ['before_position' => 1, 'body' => 'first'],
                ['before_position' => 1, 'body' => 'second'],
            ]])
            ->assertOk()
            ->assertJsonPath('data.notes.0.body', 'first')
            ->assertJsonPath('data.notes.0.sort_order', 1)
            ->assertJsonPath('data.notes.1.body', 'second')
            ->assertJsonPath('data.notes.1.sort_order', 2);
    }

    public function test_saving_notes_replaces_the_ones_that_were_there(): void
    {
        $test = $this->makeTest();

        $this->actingAs($this->admin())
            ->putJson("/api/tests/{$test->id}", ['notes' => [['before_position' => 0, 'body' => 'old']]])
            ->assertOk();

        $this->actingAs($this->admin())
            ->putJson("/api/tests/{$test->id}", ['notes' => [['before_position' => 0, 'body' => 'new']]])
            ->assertOk()
            ->assertJsonCount(1, 'data.notes')
            ->assertJsonPath('data.notes.0.body', 'new');

        $this->assertDatabaseCount('test_notes', 1);
    }

    public function test_a_save_that_says_nothing_about_notes_leaves_them_alone(): void
    {
        $test = $this->makeTest();

        $this->actingAs($this->admin())
            ->putJson("/api/tests/{$test->id}", ['notes' => [['before_position' => 0, 'body' => 'kept']]])
            ->assertOk();

        // The inline status toggle sends `status` and nothing else.
        $this->actingAs($this->admin())
            ->putJson("/api/tests/{$test->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.notes.0.body', 'kept');
    }

    public function test_a_note_is_not_what_makes_a_test_non_empty(): void
    {
        // The owner's rule, and the reason a note is not a question: it is "not
        // a question you take into account when validating the test".
        $this->actingAs($this->admin())
            ->postJson('/api/tests', [
                'title' => 'Notes only',
                'level_ids' => [DifficultyLevel::where('level_short', 'H2')->firstOrFail()->id],
                'question_ids' => [],
                'status' => 'active',
                'notes' => [['before_position' => 0, 'body' => 'TASK 1']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('question_ids');
    }

    public function test_the_exam_payload_carries_the_notes_outside_the_questions(): void
    {
        $a = $this->question('A');
        $test = $this->makeTest([$a]);
        $test->notes()->create(['before_position' => 0, 'sort_order' => 1, 'body' => 'TASK 1']);

        $response = $this->withToken($this->tokenFor($test))
            ->postJson("/api/student/tests/{$test->id}/start")
            ->assertStatus(201);

        // Numbering comes from the questions array's index, so the note must not
        // be in it — one question means question 1, note or no note.
        $response->assertJsonCount(1, 'questions')
            ->assertJsonCount(1, 'notes')
            ->assertJsonPath('notes.0.before_position', 0)
            ->assertJsonPath('notes.0.body', 'TASK 1');
    }

    public function test_a_note_is_worth_no_marks(): void
    {
        $a = $this->question('A');
        $test = $this->makeTest([$a]);
        $test->notes()->create(['before_position' => 0, 'sort_order' => 1, 'body' => 'TASK 1']);

        $token = $this->tokenFor($test);
        $attemptId = $this->withToken($token)->postJson("/api/student/tests/{$test->id}/start")->json('attempt.id');
        $this->withToken($token)->postJson("/api/student/attempts/{$attemptId}/submit", ['answers' => []])->assertOk();

        // One question worth one mark, and the note adds nothing to the total.
        $this->assertSame('1.00', Attempt::findOrFail($attemptId)->max_score);
    }

    public function test_deleting_the_test_takes_its_notes_with_it(): void
    {
        $test = $this->makeTest();
        $test->notes()->create(['before_position' => 0, 'sort_order' => 1, 'body' => 'gone too']);

        $this->actingAs($this->admin())
            ->deleteJson("/api/tests/{$test->id}")
            ->assertNoContent();

        $this->assertDatabaseCount('test_notes', 0);
    }

    // ------------------------------------------------------------- scaffolding

    /** @param list<Question> $questions */
    private function makeTest(array $questions = []): Test
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $test = Test::create(['title' => 'Reading', 'duration' => 30, 'status' => 'active']);
        $test->levels()->attach($level->id);

        foreach ($questions ?: [$this->question('A')] as $i => $question) {
            $test->questions()->attach($question->id, ['position' => $i + 1]);
        }

        return $test;
    }

    /** A competitor able to sit this test: a sample quiz around it, and a session. */
    private function tokenFor(Test $test): string
    {
        $level = DifficultyLevel::where('level_short', 'H2')->firstOrFail();

        $quiz = Quiz::create(['title' => 'Q', 'quiz_type' => 'sample', 'status' => 'active']);
        $quiz->levels()->attach($level->id);
        $exam = Exam::create(['title' => 'E', 'status' => 'active']);
        $exam->levels()->attach($level->id);
        $quiz->exams()->attach($exam->id, ['position' => 1]);
        $exam->tests()->attach($test->id, ['position' => 1]);
        $test->levels()->syncWithoutDetaching([$level->id]);

        $school = School::firstOrFail();
        Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14000777', 'sequence' => 777,
            'school_id' => $school->id, 'country_id' => $school->country_id,
            'difficulty_level_id' => $level->id, 'name' => 'Test Student',
            'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);

        return $this->postJson('/api/student/identify', [
            'competitor_number' => '14000777',
            'country_id' => $school->country_id,
            'date_of_birth' => '2010-05-01',
        ])->json('token');
    }
}
