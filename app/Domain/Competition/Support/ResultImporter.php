<?php

declare(strict_types=1);

namespace App\Domain\Competition\Support;

use Illuminate\Support\Facades\DB;

/**
 * Imports offline competition results straight into the results layer (Layer B,
 * ADR-0027) — no synthetic attempts, since an import carries no answers or
 * timing. Each row is `Student ID | Result | Qualification`: the competitor
 * number, the test score, and an optional advancement code (S/Q/F). Rows are
 * resolved to registrations by competitor number in one query and written
 * set-based (two upserts).
 *
 * Precedence on the (registration, test) key: an in-app, published result
 * (`source=attempt`) is authoritative and never overwritten by an import; a
 * prior import is updated; otherwise a new row is inserted. Qualifications are
 * independent of the test result and are (re)written per (registration, round).
 */
final class ResultImporter
{
    /** Advancement code → the exam round it fills (legacy q_semi/q_quali/q_final). */
    private const CODE_ROUND = [
        'S' => 'National round',
        'Q' => 'Regional Qualifiers',
        'F' => 'World final',
    ];

    /**
     * Import parsed data rows (header already removed) for one test.
     *
     * @param  list<list<string>>  $rows
     * @return array{imported:int,updated:int,skipped_conflict:int,not_found:int,invalid:int,qualifications:int,not_found_numbers:list<string>}
     */
    public static function import(array $rows, int $quizId, int $examRoundId, int $testId, ?int $testTypeId, ?int $userId): array
    {
        $parsed = self::parse($rows);

        $numbers = array_values(array_unique(array_column($parsed, 'number')));
        $registrations = DB::table('registrations')
            ->whereIn('competitor_number', $numbers)
            ->get(['id', 'competitor_number', 'season_id'])
            ->keyBy('competitor_number');

        // Existing rows for this test, so we can honour precedence (never clobber an
        // in-app attempt result) and count inserts vs updates.
        $existingSource = DB::table('registration_results')
            ->where('test_id', $testId)
            ->whereIn('registration_id', $registrations->pluck('id')->all() ?: [0])
            ->pluck('source', 'registration_id');

        $roundByCode = self::roundByCode();

        $now = now()->toDateTimeString();
        $resultRows = [];   // keyed by registration_id (last row for a number wins)
        $qualRows = [];     // keyed by "registration_id-round_id"
        $imported = 0;
        $updated = 0;
        $skippedConflict = 0;
        $notFound = 0;
        $invalid = 0;
        $notFoundNumbers = [];

        foreach ($parsed as $p) {
            $registration = $registrations->get($p['number']);
            if ($registration === null) {
                $notFound++;
                if (count($notFoundNumbers) < 50) {
                    $notFoundNumbers[] = $p['number'];
                }

                continue;
            }

            $registrationId = (int) $registration->id;
            $seasonId = (int) $registration->season_id;

            // Test result.
            if ($p['score'] === null) {
                $invalid++;
            } else {
                $source = $existingSource[$registrationId] ?? null;
                if ($source === 'attempt') {
                    $skippedConflict++; // in-app result is authoritative
                } else {
                    $source === 'import' ? $updated++ : $imported++;
                    $resultRows[$registrationId] = [
                        'registration_id' => $registrationId,
                        'test_id' => $testId,
                        'exam_round_id' => $examRoundId,
                        'test_type_id' => $testTypeId,
                        'quiz_id' => $quizId,
                        'season_id' => $seasonId,
                        'score' => $p['score'],
                        'max_score' => null, // an import carries no max
                        'source' => 'import',
                        'published_at' => $now,
                        'published_by' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    // A duplicate number later in the file counts as an update.
                    $existingSource[$registrationId] = 'import';
                }
            }

            // Advancement code (independent of the test result).
            $roundId = $p['qual'] !== null ? ($roundByCode[$p['qual']] ?? null) : null;
            if ($roundId !== null) {
                $qualRows[$registrationId.'-'.$roundId] = [
                    'registration_id' => $registrationId,
                    'exam_round_id' => $roundId,
                    'season_id' => $seasonId,
                    'code' => $p['qual'],
                    'source' => 'import',
                    'published_at' => $now,
                    'published_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::transaction(function () use ($resultRows, $qualRows) {
            if ($resultRows !== []) {
                DB::table('registration_results')->upsert(
                    array_values($resultRows),
                    ['registration_id', 'test_id'],
                    ['exam_round_id', 'test_type_id', 'quiz_id', 'season_id', 'score', 'max_score', 'source', 'published_at', 'published_by', 'updated_at'],
                );
            }
            if ($qualRows !== []) {
                DB::table('registration_qualifications')->upsert(
                    array_values($qualRows),
                    ['registration_id', 'exam_round_id'],
                    ['season_id', 'code', 'source', 'published_at', 'published_by', 'updated_at'],
                );
            }
        });

        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped_conflict' => $skippedConflict,
            'not_found' => $notFound,
            'invalid' => $invalid,
            'qualifications' => count($qualRows),
            'not_found_numbers' => $notFoundNumbers,
        ];
    }

    /**
     * Normalize the raw rows: drop blank lines, keep the competitor number, parse
     * the score (European comma accepted) and the optional S/Q/F code.
     *
     * @param  list<list<string>>  $rows
     * @return list<array{number:string,score:?float,qual:?string}>
     */
    private static function parse(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $number = trim((string) ($row[0] ?? ''));
            if ($number === '') {
                continue;
            }

            $out[] = [
                'number' => $number,
                'score' => self::parseScore(trim((string) ($row[1] ?? ''))),
                'qual' => self::parseQualification((string) ($row[2] ?? '')),
            ];
        }

        return $out;
    }

    private static function parseScore(string $raw): ?float
    {
        if ($raw === '') {
            return null;
        }

        $normalized = str_replace(',', '.', $raw);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private static function parseQualification(string $raw): ?string
    {
        $code = mb_strtoupper(trim($raw));

        return array_key_exists($code, self::CODE_ROUND) ? $code : null;
    }

    /**
     * Resolve each advancement code to its exam-round id (by canonical name).
     *
     * @return array<string, int|null>
     */
    private static function roundByCode(): array
    {
        $idByName = DB::table('exam_rounds')
            ->whereIn('name', array_values(self::CODE_ROUND))
            ->pluck('id', 'name');

        return array_map(fn (string $name) => $idByName[$name] ?? null, self::CODE_ROUND);
    }
}
