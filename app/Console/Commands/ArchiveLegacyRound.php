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
    protected $signature = 'legacy:archive-round {table : legacy el_student[_bekap<year>] table} {--round= : round number (else derived from the competitor-number prefix)} {--chunk=2000}';

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

        // Idempotent: clear this round's archive before re-writing it.
        DB::table('archive_registrations')->where('round_number', $round)->delete();
        DB::table('archive_registration_results')->where('round_number', $round)->delete();
        DB::table('archive_registration_qualifications')->where('round_number', $round)->delete();

        $select = ['e.student_id', 'c.country_name', 'dl.level_short', 's.name as school_name', 'rg.name as region_name'];
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
            ->chunk((int) $this->option('chunk'), function ($rows) use ($season, $round, $now, $resultCols, $qualCols, $archived, &$counts): void {
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

                    $roster[] = [
                        'season_id' => $season->id, 'round_number' => $round,
                        'competitor_number' => $number, 'name' => '',
                        'country' => $country, 'region' => $region, 'venue' => $venue, 'school_external' => null,
                        'level' => $r->level_short, 'grade' => $grade, 'attendance' => null, 'archived_at' => $now,
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
                            'level' => $r->level_short, 'exam_round' => $examRound, 'test_type' => $type,
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
