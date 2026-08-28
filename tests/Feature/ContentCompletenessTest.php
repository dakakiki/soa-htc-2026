<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuestionAnswer;
use App\Domain\Assessment\Models\Test;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nothing inconsistent may be active.
 *
 * The exit condition of Phase 2 in `docs/01_DEVELOPMENT_ROADMAP.md` — "the
 * system does not allow an inconsistent configuration to be published" — which
 * nothing enforced until 2026-08-28. A test could be activated with no
 * questions, and a multiple-choice question with no correct answer.
 *
 * The gate is on being ACTIVE, never on saving: a draft is allowed to be as
 * unfinished as its author needs. And it reads the state the save would LEAVE
 * BEHIND, not the payload, because the list screens PUT nothing but `status`.
 */
class ContentCompletenessTest extends TestCase
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

    private function levelId(): int
    {
        return DifficultyLevel::where('level_short', 'H2')->firstOrFail()->id;
    }

    /** A complete question, so a test can be about the test. */
    private function usableQuestion(): Question
    {
        $question = Question::create([
            'title' => 'Q', 'question_type' => 'multiple_choice', 'points' => 1, 'status' => 'active',
        ]);
        QuestionAnswer::create(['question_id' => $question->id, 'text' => 'Right', 'is_correct' => true, 'position' => 1]);
        QuestionAnswer::create(['question_id' => $question->id, 'text' => 'Wrong', 'is_correct' => false, 'position' => 2]);

        return $question;
    }

    /** @param array<string, mixed> $overrides */
    private function draftPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'A test',
            'level_ids' => [$this->levelId()],
            'question_ids' => [],
        ], $overrides);
    }

    // ---------------------------------------------------------------- tests

    public function test_an_active_test_cannot_be_created_without_questions(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/tests', $this->draftPayload(['status' => 'active']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('question_ids');
    }

    public function test_saying_nothing_about_status_is_saying_active(): void
    {
        // `tests.status` is `default('active')`, so silence is not a draft.
        $this->actingAs($this->admin())
            ->postJson('/api/tests', $this->draftPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('question_ids');
    }

    public function test_the_same_test_may_be_saved_as_a_draft(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/tests', $this->draftPayload(['status' => 'inactive']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_a_draft_test_cannot_be_switched_on_by_the_list_toggle(): void
    {
        $test = Test::create(['title' => 'Empty', 'status' => 'inactive']);

        // What the inline toggle sends: `status`, and nothing else at all.
        $this->actingAs($this->admin())
            ->putJson("/api/tests/{$test->id}", ['status' => 'active'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('question_ids');
    }

    public function test_a_test_with_questions_switches_on_normally(): void
    {
        $test = Test::create(['title' => 'Ready', 'status' => 'inactive']);
        $test->questions()->attach($this->usableQuestion()->id, ['position' => 1]);

        $this->actingAs($this->admin())
            ->putJson("/api/tests/{$test->id}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_emptying_an_active_test_is_refused(): void
    {
        $test = Test::create(['title' => 'Ready', 'status' => 'active']);
        $test->questions()->attach($this->usableQuestion()->id, ['position' => 1]);

        $this->actingAs($this->admin())
            ->putJson("/api/tests/{$test->id}", ['question_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('question_ids');
    }

    public function test_an_active_test_can_always_be_stood_down(): void
    {
        $test = Test::create(['title' => 'Empty but active', 'status' => 'active']);

        $this->actingAs($this->admin())
            ->putJson("/api/tests/{$test->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_an_active_multiple_choice_question_needs_a_correct_answer(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/questions', [
                'title' => 'Pick one',
                'question_type' => 'multiple_choice',
                'points' => 1,
                'status' => 'active',
                'answers' => [
                    ['text' => 'a', 'is_correct' => false, 'position' => 1],
                    ['text' => 'b', 'is_correct' => false, 'position' => 2],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers');
    }

    public function test_a_question_with_no_answers_at_all_is_left_alone(): void
    {
        // A heading between questions, which is the only way this application
        // can hold one today — and how the legacy import brought twenty across.
        // Entered content must be shown as entered (owner, 2026-08-28), so the
        // gate must not force its author to invent a correct answer or hide it.
        $this->actingAs($this->admin())
            ->postJson('/api/questions', [
                'title' => 'TASK 2 Look at the pictures and choose a, b or c',
                'question_type' => 'multiple_choice',
                'points' => 1,
                'status' => 'active',
                'answers' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_but_one_wrong_answer_is_enough_to_ask_for_a_right_one(): void
    {
        // The line between the two: nothing under it is a heading, something
        // under it is a question, and a question has to be answerable.
        $this->actingAs($this->admin())
            ->postJson('/api/questions', [
                'title' => 'Pick one',
                'question_type' => 'multiple_choice',
                'points' => 1,
                'status' => 'active',
                'answers' => [['text' => 'only option', 'is_correct' => false, 'position' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers');
    }

    public function test_the_same_question_may_be_saved_as_a_draft(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/questions', [
                'title' => 'Pick one',
                'question_type' => 'multiple_choice',
                'points' => 1,
                'status' => 'inactive',
                'answers' => [['text' => 'a', 'is_correct' => false, 'position' => 1]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_the_inline_toggle_cannot_switch_on_a_question_whose_stored_answers_are_wrong(): void
    {
        $question = Question::create([
            'title' => 'Pick one', 'question_type' => 'multiple_choice', 'points' => 1, 'status' => 'inactive',
        ]);
        QuestionAnswer::create(['question_id' => $question->id, 'text' => 'a', 'is_correct' => false, 'position' => 1]);

        // The request never mentions the answers. The rule still reads them.
        $this->actingAs($this->admin())
            ->putJson("/api/questions/{$question->id}", ['status' => 'active'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers');
    }

    public function test_an_incomplete_question_can_always_be_stood_down(): void
    {
        $question = Question::create([
            'title' => 'Pick one', 'question_type' => 'multiple_choice', 'points' => 1, 'status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->putJson("/api/questions/{$question->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_an_essay_needs_no_answers(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/questions', [
                'title' => 'Write about your summer',
                'question_type' => 'essay',
                'points' => 5,
                'status' => 'active',
                'answers' => [],
            ])
            ->assertCreated();
    }

    public function test_a_gap_that_accepts_nothing_is_refused(): void
    {
        // A gap is there, and nothing counts as filling it — the same defect as
        // a multiple-choice question with no correct answer, in the other type.
        $this->actingAs($this->admin())
            ->postJson('/api/questions', [
                'title' => 'Fill it in',
                'question_type' => 'gap_filling',
                'points' => 1,
                'status' => 'active',
                'answers' => [['text' => '   ', 'is_correct' => true, 'position' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers');
    }

    public function test_a_gap_filling_question_with_a_gap_is_accepted(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/questions', [
                'title' => 'Fill it in',
                'question_type' => 'gap_filling',
                'points' => 1,
                'status' => 'active',
                'answers' => [['text' => 'went|gone', 'is_correct' => true, 'position' => 1]],
            ])
            ->assertCreated();
    }

    public function test_changing_the_type_to_essay_makes_the_answers_beside_the_point(): void
    {
        $question = Question::create([
            'title' => 'Pick one', 'question_type' => 'multiple_choice', 'points' => 1, 'status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->putJson("/api/questions/{$question->id}", ['question_type' => 'essay'])
            ->assertOk()
            ->assertJsonPath('data.question_type', 'essay');
    }

    public function test_the_author_is_told_what_to_do_about_it(): void
    {
        $message = (string) $this->actingAs($this->admin())
            ->postJson('/api/questions', [
                'title' => 'Pick one',
                'question_type' => 'multiple_choice',
                'points' => 1,
                'status' => 'active',
                'answers' => [['text' => 'a', 'is_correct' => false, 'position' => 1]],
            ])
            ->assertStatus(422)
            ->json('message');

        // `apiErrorMessage` shows `message` and nothing else, so it has to carry
        // both halves: what is wrong, and the way out.
        $this->assertStringContainsString('marked correct', $message);
        $this->assertStringContainsString('active', $message);
    }
}
