<?php

declare(strict_types=1);

namespace App\Domain\Competition\Support;

use App\Domain\Assessment\Enums\QuestionType;
use App\Support\XlsxWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the two results exports (ADR-0027, Faza 4) as [headers, rows] pairs the
 * caller writes with {@see XlsxWriter}.
 *
 * "All" is a wide, one-row-per-competitor sheet read from the results layer
 * (Layer B): identity columns, then — driven by {@see RegistrationResults::columns()},
 * never hardcoded like the legacy Preliminary+National — a score column per round
 * test type with a round total, an advancement code for the test-less rounds
 * (Regional Qualifiers / World final), and a grand total.
 *
 * "With answers" is one row per competitor for a single test, read from Layer A
 * (attempts / attempt_answers): identity, each question's rendered response with a
 * correctness marker, and the attempt score. Imported results have no answers, so
 * this export is in-app only.
 */
final class ResultExporter
{
    /**
     * The wide results export for the given registrations.
     *
     * @param  list<int>  $registrationIds
     * @return array{0: list<string>, 1: list<list<string|int|float|null>>}
     */
    public static function all(array $registrationIds): array
    {
        $columns = RegistrationResults::columns();

        $headers = ['Student ID', 'Name', 'Date of birth', 'Level', 'Country', 'Region', 'Venue', 'External school'];
        foreach ($columns as $col) {
            if ($col['types'] !== []) {
                foreach ($col['types'] as $type) {
                    $headers[] = $col['round'].' — '.$type['name'];
                }
                $headers[] = $col['round'].' — Total';
            } else {
                $headers[] = $col['round']; // advancement code column
            }
        }
        $headers[] = 'Total';

        if ($registrationIds === []) {
            return [$headers, []];
        }

        $registrations = DB::table('registrations as r')
            ->leftJoin('countries as c', 'r.country_id', '=', 'c.id')
            ->leftJoin('schools as s', 'r.school_id', '=', 's.id')
            ->leftJoin('regions as rg', 's.region_id', '=', 'rg.id')
            ->leftJoin('difficulty_levels as dl', 'r.difficulty_level_id', '=', 'dl.id')
            ->whereIn('r.id', $registrationIds)
            ->orderBy('c.name')->orderBy('s.name')->orderBy('r.name')
            ->get([
                'r.id', 'r.competitor_number', 'r.name', 'r.date_of_birth', 'r.school_external',
                'dl.level_short as level', 'c.name as country', 'rg.name as region', 's.name as venue',
            ]);

        $grid = RegistrationResults::forRegistrations($registrationIds);

        $rows = [];
        foreach ($registrations as $reg) {
            $cells = $grid[(int) $reg->id] ?? [];
            $row = [
                $reg->competitor_number, $reg->name, $reg->date_of_birth, $reg->level,
                $reg->country, $reg->region, $reg->venue, $reg->school_external,
            ];

            $total = 0.0;
            foreach ($columns as $col) {
                $cell = $cells[$col['round_id']] ?? [];
                if ($col['types'] !== []) {
                    foreach ($col['types'] as $type) {
                        $row[] = $cell['types'][$type['id']] ?? null;
                    }
                    $sum = $cell['sum'] ?? null;
                    $row[] = $sum;
                    $total += (float) ($sum ?? 0.0);
                } else {
                    $row[] = $cell['qual'] ?? null;
                }
            }
            $row[] = $total;

            $rows[] = $row;
        }

        return [$headers, $rows];
    }

    /**
     * The per-question answers export for one test and the given registrations.
     *
     * @param  list<int>  $registrationIds
     * @return array{0: list<string>, 1: list<list<string|int|float|null>>}
     */
    public static function withAnswers(int $testId, array $registrationIds): array
    {
        $questions = DB::table('question_test as qt')
            ->join('questions as q', 'q.id', '=', 'qt.question_id')
            ->where('qt.test_id', $testId)
            ->where('q.status', 'active')
            ->orderBy('qt.position')
            ->get(['q.id', 'q.question_type']);

        $headers = ['Student ID', 'Name', 'Date of birth', 'Level', 'Country', 'Venue'];
        foreach ($questions as $i => $q) {
            $headers[] = 'Q'.($i + 1);
        }
        $headers[] = 'Score';

        if ($registrationIds === [] || $questions->isEmpty()) {
            return [$headers, []];
        }

        // Option labels for resolving multiple-choice selections to their text.
        $optionText = DB::table('question_answers')
            ->whereIn('question_id', $questions->pluck('id'))
            ->pluck('text', 'id');

        $attempts = DB::table('attempts as a')
            ->join('registrations as r', 'r.id', '=', 'a.registration_id')
            ->leftJoin('countries as c', 'r.country_id', '=', 'c.id')
            ->leftJoin('schools as s', 'r.school_id', '=', 's.id')
            ->leftJoin('difficulty_levels as dl', 'r.difficulty_level_id', '=', 'dl.id')
            ->where('a.test_id', $testId)
            ->where('a.status', 'completed')
            ->whereIn('a.registration_id', $registrationIds)
            ->orderBy('c.name')->orderBy('s.name')->orderBy('r.name')
            ->get([
                'a.id as attempt_id', 'a.score',
                'r.competitor_number', 'r.name', 'r.date_of_birth',
                'dl.level_short as level', 'c.name as country', 's.name as venue',
            ]);

        // answers[attempt_id][question_id] = {response, is_correct}
        $answers = [];
        foreach (DB::table('attempt_answers')->whereIn('attempt_id', $attempts->pluck('attempt_id'))->get(['attempt_id', 'question_id', 'response', 'is_correct']) as $a) {
            $answers[(int) $a->attempt_id][(int) $a->question_id] = $a;
        }

        $rows = [];
        foreach ($attempts as $attempt) {
            $byQuestion = $answers[(int) $attempt->attempt_id] ?? [];
            $row = [
                $attempt->competitor_number, $attempt->name, $attempt->date_of_birth,
                $attempt->level, $attempt->country, $attempt->venue,
            ];
            foreach ($questions as $q) {
                $row[] = self::renderAnswer((string) $q->question_type, $byQuestion[(int) $q->id] ?? null, $optionText);
            }
            // 🪤 Cast, like every other score leaving the results layer
            // (`RegistrationResults` casts all of them). A query builder hands
            // back a decimal as the driver formats it — '2' from SQLite, '2.00'
            // from MySQL — so without this the exported file differs by which
            // database produced it.
            $row[] = $attempt->score === null ? null : (float) $attempt->score;

            $rows[] = $row;
        }

        return [$headers, $rows];
    }

    /**
     * Render one answer as a cell: a correctness marker (✓/✗ for auto-graded
     * questions) followed by the response — selected option labels, gap entries,
     * or the essay text.
     *
     * @param  Collection<int, string>  $optionText
     */
    private static function renderAnswer(string $type, ?object $answer, $optionText): ?string
    {
        if ($answer === null) {
            return null;
        }

        $response = json_decode((string) $answer->response, true);
        $response = is_array($response) ? $response : [];
        $marker = $answer->is_correct === null ? '' : ((int) $answer->is_correct === 1 ? '✓ ' : '✗ ');

        return match ($type) {
            QuestionType::MultipleChoice->value => $marker.self::joinList(
                array_map(fn ($id) => (string) ($optionText[(int) $id] ?? $id), $response['selected'] ?? []),
                ', ',
            ),
            QuestionType::GapFilling->value => $marker.self::joinList(
                array_map(fn ($gap) => (string) $gap, is_array($response['gaps'] ?? null) ? $response['gaps'] : []),
                ' | ',
            ),
            QuestionType::Essay->value => (string) ($response['text'] ?? ''),
            default => '',
        };
    }

    /**
     * @param  list<string>  $parts
     */
    private static function joinList(array $parts, string $glue): string
    {
        return implode($glue, $parts);
    }
}
