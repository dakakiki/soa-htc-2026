<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Migration\LegacyText;
use App\Domain\Organization\Enums\SeasonStatus;
use App\Domain\Organization\Models\Season;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Archive one legacy season into the results archive (Layer C, ADR-0027 / OD-9).
 * Reads a legacy `el_student[_bekap<year>]` table — the denormalized per-student
 * roster + marks — over the `legacy` connection and writes, for that competition
 * round: the WHOLE roster (`archive_registrations`), whatever results exist
 * (`archive_registration_results`, one row per component mark), and the S/Q/F
 * advancement codes (`archive_registration_qualifications`).
 *
 * No personal data is copied — only competitor number, geography, difficulty
 * level, grade and scores. `el_student` is the primary source (full roster, plus
 * marks + qualifications for recent rounds); for older rounds whose marks were
 * already archived and cleared from el_student, results fall back per competitor
 * to the legacy `archive_test_results` (component scores agree where both exist).
 * The stored legacy `total` is drifted, so only component marks are archived and
 * totals are recomputed at read time.
 *
 * Idempotent per round (clears the round first). Tolerates per-year schema drift
 * — mark/qualification/grade columns absent in an older backup are simply skipped.
 */
class ArchiveLegacyRound extends Command
{
    protected $signature = 'legacy:archive-round {table : legacy el_student[_bekap<year>] table} {--round= : round number (else derived from the competitor-number prefix)} {--levels= : legacy|current (else detected from the table)} {--chunk=2000}';

    protected $description = 'Archive one legacy season (el_student[_bekap<year>]) into the results archive: roster + results + S/Q/F. No personal data.';

    /** Legacy mark column → [exam round, test type]. */
    private const RESULTS = [
        'read_mark' => ['Preliminary round', 'Reading'],
        'use_mark' => ['Preliminary round', 'Use of English'],
        'semi_read' => ['National round', 'Reading'],
        'semi_write' => ['National round', 'Writing'],
    ];

    /** Legacy qualification flag → [code, exam round]. */
    private const QUALIFICATIONS = [
        'q_semi' => ['S', 'National round'],
        'q_quali' => ['Q', 'Regional Qualifiers'],
        'q_final' => ['F', 'World final'],
    ];

    /*
     * Rounds up to 2023 name a level by the OLD scheme's own code, not by a row id
     * in `difficulty_category_levels` — that table was only created in 11.2023.
     * The codes come from the legacy application itself (`app/DifficultyLevel.php`
     * and the add-student form), and the mapping is not optional reading:
     *
     * 🔴 the old codes 1–5 mean **Hippo 1–5**, while ids 1–5 in the new table mean
     * BH, LH, H1, H2. Joining one against the other does not fail — it quietly
     * answers two levels too easy, and drops the largest group of all (old `1`,
     * which has no id 1 to match) to NULL.
     *
     * The three Special levels are kept under their own names (owner, 2026-09-14):
     * they were defined by year of birth, not by grade, and today's S1–S5 are five
     * bands where these were three. Renaming them would invent a precision the
     * archive never had.
     */
    private const LEGACY_LEVELS = [
        'L1' => 'LH', 'LITTLE' => 'LH',
        '1' => 'H1', 'HIPPO_1' => 'H1',
        '2' => 'H2', 'HIPPO_2' => 'H2',
        '3' => 'H3', 'HIPPO_3' => 'H3',
        '4' => 'H4', 'HIPPO_4' => 'H4',
        '5' => 'H5', 'HIPPO_5' => 'H5',
        'S10' => 'S10', 'S15' => 'S15', 'S19' => 'S19',
    ];

    /**
     * A code only the old scheme ever used. Its presence settles which scheme the
     * table speaks, which a single value cannot: `2` is legal in both and means a
     * different level in each.
     */
    private const LEGACY_ONLY = ['L1', 'LITTLE', 'S10', 'S15', 'S19', 'HIPPO_1', 'HIPPO_2', 'HIPPO_3', 'HIPPO_4', 'HIPPO_5'];

    /**
     * A legacy level value reduced to the form the mapping is keyed by. The column
     * was free text typed over many years, so the same level arrives as `S15`,
     * `S 15` and ` S15 ` in one table.
     */
    private function normaliseLevel(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', trim($value)));
    }

    public function handle(): int
    {
        $table = (string) $this->argument('table');
        if (preg_match('/^el_student(_bekap\d{4}(_new)?)?$/', $table) !== 1) {
            $this->error("Refusing to read an unexpected table name: '{$table}'.");

            return self::FAILURE;
        }

        $legacy = DB::connection('legacy');
        $columns = $legacy->getSchemaBuilder()->getColumnListing($table);
        $has = fn (string $c): bool => in_array($c, $columns, true);

        $round = $this->option('round') !== null
            ? (int) $this->option('round')
            : (int) mb_substr((string) $legacy->table($table)->orderBy('entry_id')->value('student_id'), 0, 2);
        if ($round <= 0) {
            $this->error('Could not determine the round number.');

            return self::FAILURE;
        }

        // Only the mark/qualification/grade columns actually present this year.
        $resultCols = array_filter(self::RESULTS, fn ($c) => $has($c), ARRAY_FILTER_USE_KEY);
        $qualCols = array_filter(self::QUALIFICATIONS, fn ($c) => $has($c), ARRAY_FILTER_USE_KEY);

        // Older rounds have their marks archived and cleared from el_student — fall
        // back to the legacy `archive_test_results` (denormalized p_/s_ columns)
        // for any competitor el_student has no marks for. Keyed by competitor number.
        $archived = [];
        if ($legacy->getSchemaBuilder()->hasTable('archive_test_results')) {
            foreach ($legacy->table('archive_test_results')->where('round_number', $round)
                ->select('student_id', 'p_r', 'p_u', 's_r', 's_w')->get() as $a) {
                $archived[(string) $a->student_id] = $a;
            }
        }

        $season = Season::query()->firstOrCreate(
            ['round_number' => $round],
            ['name' => "Round {$round}", 'year' => 2012 + $round, 'status' => SeasonStatus::Archived->value],
        );

        /*
         * Which scheme this year's `level` column speaks. Decided per table and not
         * per row, because the two schemes overlap on the bare digits: seeing a `2`
         * says nothing, seeing an `L1` anywhere in the table says everything.
         */
        $legacyScheme = match ($this->option('levels')) {
            'legacy' => true,
            'current' => false,
            default => $legacy->table($table)->distinct()->pluck('level')
                ->contains(fn ($v) => in_array($this->normaliseLevel((string) $v), self::LEGACY_ONLY, true)),
        };
        $this->line($legacyScheme
            ? 'Levels: the old scheme (L1 / 1–5 / S10–S19), mapped by name.'
            : 'Levels: difficulty_category_levels ids, joined.');

        // Idempotent: clear this round's archive before re-writing it.
        DB::table('archive_registrations')->where('round_number', $round)->delete();
        DB::table('archive_registration_results')->where('round_number', $round)->delete();
        DB::table('archive_registration_qualifications')->where('round_number', $round)->delete();

        $select = ['e.student_id', 'c.country_name', 'dl.level_short', 'e.level as raw_level', 's.name as school_name', 'rg.name as region_name'];
        $select[] = $has('class') ? 'e.class' : DB::raw('NULL as class');
        foreach ([...array_keys($resultCols), ...array_keys($qualCols)] as $col) {
            $select[] = "e.{$col}";
        }

        $now = now()->toDateTimeString();
        $counts = ['roster' => 0, 'results' => 0, 'quals' => 0];

        $legacy->table($table.' as e')
            ->leftJoin('el_country as c', 'c.country_id', '=', 'e.country_id')
            ->leftJoin('difficulty_category_levels as dl', 'dl.id', '=', 'e.level')
            ->leftJoin('schools as s', 's.id', '=', 'e.school_id')
            ->leftJoin('regions as rg', 'rg.id', '=', 's.region_id')
            ->orderBy('e.entry_id')
            ->select($select)
            ->chunk((int) $this->option('chunk'), function ($rows) use ($season, $round, $now, $resultCols, $qualCols, $archived, $legacyScheme, &$counts): void {
                $roster = [];
                $results = [];
                $quals = [];

                foreach ($rows as $r) {
                    $number = trim((string) $r->student_id);
                    if ($number === '') {
                        continue;
                    }

                    $country = $r->country_name === null ? null : LegacyText::fix((string) $r->country_name);
                    $region = $r->region_name === null ? null : LegacyText::fix((string) $r->region_name);
                    $venue = $r->school_name === null ? null : LegacyText::fix((string) $r->school_name);
                    $grade = is_numeric($r->class) && (int) $r->class >= 1 && (int) $r->class <= 20 ? (int) $r->class : null;
                    $level = $legacyScheme
                        ? (self::LEGACY_LEVELS[$this->normaliseLevel((string) $r->raw_level)] ?? null)
                        : $r->level_short;

                    $roster[] = [
                        'season_id' => $season->id, 'round_number' => $round,
                        'competitor_number' => $number, 'name' => '',
                        'country' => $country, 'region' => $region, 'venue' => $venue, 'school_external' => null,
                        'level' => $level, 'grade' => $grade, 'attendance' => null, 'archived_at' => $now,
                    ];
                    $counts['roster']++;

                    // Results: el_student marks, else fall back to the legacy archive.
                    $marks = [];
                    foreach ($resultCols as $col => [$examRound, $type]) {
                        if ((float) $r->{$col} > 0) {
                            $marks[] = [$examRound, $type, (float) $r->{$col}];
                        }
                    }
                    if ($marks === [] && isset($archived[$number])) {
                        $a = $archived[$number];
                        foreach ([['Preliminary round', 'Reading', $a->p_r], ['Preliminary round', 'Use of English', $a->p_u], ['National round', 'Reading', $a->s_r], ['National round', 'Writing', $a->s_w]] as [$examRound, $type, $sc]) {
                            if ((float) $sc > 0) {
                                $marks[] = [$examRound, $type, (float) $sc];
                            }
                        }
                    }
                    foreach ($marks as [$examRound, $type, $score]) {
                        $results[] = [
                            'season_id' => $season->id, 'round_number' => $round,
                            'competitor_number' => $number, 'student_name' => '',
                            'country' => $country, 'region' => $region, 'venue' => $venue, 'school_external' => null,
                            'level' => $level, 'exam_round' => $examRound, 'test_type' => $type,
                            'quiz' => null, 'test' => null, 'score' => $score, 'max_score' => null,
                            'source' => 'legacy', 'published_at' => null, 'archived_at' => $now,
                        ];
                        $counts['results']++;
                    }

                    foreach ($qualCols as $col => [$code, $examRound]) {
                        if (trim((string) $r->{$col}) !== '') {
                            $quals[] = [
                                'season_id' => $season->id, 'round_number' => $round,
                                'competitor_number' => $number, 'student_name' => '',
                                'exam_round' => $examRound, 'code' => $code,
                                'published_at' => null, 'archived_at' => $now,
                            ];
                            $counts['quals']++;
                        }
                    }
                }

                foreach (array_chunk($roster, 500) as $batch) {
                    DB::table('archive_registrations')->insert($batch);
                }
                foreach (array_chunk($results, 500) as $batch) {
                    DB::table('archive_registration_results')->insert($batch);
                }
                foreach (array_chunk($quals, 500) as $batch) {
                    DB::table('archive_registration_qualifications')->insert($batch);
                }
            });

        $this->info("Archived round {$round} (season #{$season->id}): {$counts['roster']} roster, {$counts['results']} results, {$counts['quals']} qualifications.");

        return self::SUCCESS;
    }
}
