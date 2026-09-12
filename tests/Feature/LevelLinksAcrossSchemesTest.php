<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test as AssessmentTest;
use App\Domain\Assessment\Support\LegacyLevelMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A fresh install carries the two Default schemes and nothing else, so every
 * `level_short` is unique and none of this can go wrong. The legacy import then
 * brings the "…7" schemes in beside them, and from that moment BABY HIPPO is a
 * level of two categories at once.
 *
 * The import's level map was keyed on (type, level_short), which from that
 * moment matched twice: the later row won, and every quiz, exam and test came
 * out linked to the "…7" half alone. Nothing failed and nothing was logged —
 * 91,336 of 108,771 competitors simply identified and were shown an empty
 * screen, because `StudentAvailability` gates the whole tree on this pivot.
 */
class LevelLinksAcrossSchemesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_a_fresh_install_has_each_level_once_which_is_why_this_stayed_hidden(): void
    {
        $this->assertSame(1, DifficultyLevel::where('level_short', 'H2')->count());
    }

    public function test_legacy_level_map_never_folds_two_legacy_levels_onto_one(): void
    {
        $this->secondSchemeOf('H2');

        $map = LegacyLevelMap::make();

        $this->assertNotEmpty($map);
        $this->assertSame(
            count($map),
            count(array_unique($map)),
            'Two legacy levels resolved to one of ours — the fold that emptied the Default schemes.',
        );
    }

    public function test_repair_mirrors_a_link_onto_every_scheme_sharing_the_level(): void
    {
        $default = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        $seven = $this->secondSchemeOf('H2');
        [$quiz, $exam, $test] = $this->treeLinkedTo($seven);

        $this->artisan('levels:repair-links')->assertSuccessful();

        foreach ([$default, $seven] as $level) {
            $this->assertDatabaseHas('difficulty_level_quiz', ['quiz_id' => $quiz->id, 'difficulty_level_id' => $level->id]);
            $this->assertDatabaseHas('difficulty_level_exam', ['exam_id' => $exam->id, 'difficulty_level_id' => $level->id]);
            $this->assertDatabaseHas('difficulty_level_test', ['test_id' => $test->id, 'difficulty_level_id' => $level->id]);
        }
    }

    public function test_a_level_the_quiz_never_covered_is_not_dragged_in(): void
    {
        $seven = $this->secondSchemeOf('H2');
        [$quiz] = $this->treeLinkedTo($seven);

        $this->artisan('levels:repair-links')->assertSuccessful();

        // Mirroring is per level, not per category: H3 shares a scheme with H2
        // and must stay out of it.
        $h3 = DifficultyLevel::where('level_short', 'H3')->firstOrFail();
        $this->assertDatabaseMissing('difficulty_level_quiz', ['quiz_id' => $quiz->id, 'difficulty_level_id' => $h3->id]);
    }

    public function test_running_it_twice_adds_nothing_the_second_time(): void
    {
        $this->treeLinkedTo($this->secondSchemeOf('H2'));

        $this->artisan('levels:repair-links')->assertSuccessful();
        $after = DB::table('difficulty_level_quiz')->count();

        $this->artisan('levels:repair-links')->assertSuccessful();

        $this->assertSame($after, DB::table('difficulty_level_quiz')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $default = DifficultyLevel::where('level_short', 'H2')->firstOrFail();
        [$quiz] = $this->treeLinkedTo($this->secondSchemeOf('H2'));

        $this->artisan('levels:repair-links', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseMissing('difficulty_level_quiz', ['quiz_id' => $quiz->id, 'difficulty_level_id' => $default->id]);
    }

    /**
     * The same level in a second scheme — what the legacy import adds, and the
     * state in which the fold bites.
     */
    private function secondSchemeOf(string $short): DifficultyLevel
    {
        $existing = DifficultyLevel::where('level_short', $short)->firstOrFail();

        $category = DifficultyCategory::create([
            'name' => 'Regular 7',
            'type' => 'regular',
            'countries_all' => false,
            'status' => 'active',
            'legacy_id' => 2,
        ]);

        return DifficultyLevel::create([
            'difficulty_category_id' => $category->id,
            'level_short' => $short,
            'name' => $existing->name,
            'grades' => $existing->grades,
            'position' => $existing->position,
            'status' => 'active',
            'legacy_id' => 12,
        ]);
    }

    /**
     * A quiz with one exam and one test, every layer linked to $level alone —
     * the shape the folded import left behind.
     *
     * @return array{0: Quiz, 1: Exam, 2: AssessmentTest}
     */
    private function treeLinkedTo(DifficultyLevel $level): array
    {
        $quiz = Quiz::create(['title' => 'Repair fixture', 'quiz_type' => 'competition', 'status' => 'active']);
        $exam = Exam::create(['title' => 'Repair fixture exam', 'status' => 'active']);
        $test = AssessmentTest::create(['title' => 'Repair fixture test', 'status' => 'active']);

        $quiz->levels()->sync([$level->id]);
        $exam->levels()->sync([$level->id]);
        $test->levels()->sync([$level->id]);

        return [$quiz, $exam, $test];
    }
}
