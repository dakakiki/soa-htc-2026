<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Migration\LegacyCountries;
use App\Domain\Organization\Models\Season;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The round in play, from the legacy database into the active season.
 *
 * Every other `legacy:import-*` command moves configuration — countries, schools,
 * questions, tests. This one moves the competition itself: the roster of the
 * round that is running, so the new site can show what the old one shows.
 *
 * It is deliberately roster-only. Marks arrive from `quiz_results` through
 * {@see ImportLegacyResults}, because that is the table the legacy application
 * itself reads on both the results screen and the export — the aggregate columns
 * on `el_student` are output it computes, not a source.
 *
 * Idempotent on `competitor_number`, which is the number a competitor identifies
 * with and the one thing that cannot change under them. Re-running against a
 * newer dump updates what moved and adds what is new.
 */
class ImportLegacyRegistrations extends Command
{
    protected $signature = 'legacy:import-registrations
        {--chunk=2000 : rows read per pass}
        {--replace-local : delete registrations that carry no legacy id first — see the warning it prints}
        {--dry-run : report what would happen and write nothing}';

    protected $description = 'Import the legacy roster of the round in play into the active season';

    public function handle(): int
    {
        $season = Season::query()->where('status', 'active')->first();

        if ($season === null) {
            $this->error('No active season. A roster has nowhere to land.');

            return self::FAILURE;
        }

        $legacy = DB::connection('legacy');
        $dryRun = (bool) $this->option('dry-run');

        $this->line("Season: {$season->name} (round {$season->round_number})");

        if (! $this->clearLocalRoster($season, $dryRun)) {
            return self::FAILURE;
        }

        /*
         * 🪤 Schools resolve through `legacy_id_maps`, never through
         * `schools.legacy_id`. The schools import merges duplicates, so a legacy
         * id that was merged away has no column of its own — and five of them
         * carry 124 competitors in the current dump. The map is what keeps a
         * many-to-one dedup reconcilable, which is the whole reason it exists.
         */
        $schools = DB::table('legacy_id_maps')
            ->where('source_table', 'schools')
            ->pluck('target_id', 'source_pk')
            ->map(fn ($id) => (int) $id)
            ->all();

        $countries = LegacyCountries::map();
        $levels = DB::table('difficulty_levels')
            ->whereNotNull('legacy_id')
            ->pluck('id', 'legacy_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $renumbered = $this->renumberDuplicates($legacy, $season);

        // Two ways back for rows the legacy form left incomplete — see where
        // each is used below.
        $venuesByName = $this->venuesByName();
        $categoryByLegacySchool = $this->categoryByLegacySchool($legacy);
        $levelByGrade = $this->levelByGrade();

        $counts = ['written' => 0, 'no_school' => 0, 'no_level' => 0, 'no_country' => 0, 'bad_date' => 0,
            'school_by_name' => 0, 'level_by_grade' => 0];
        $quarantine = [];
        $now = now();
        $total = (int) $legacy->table('el_student')->count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $legacy->table('el_student')
            ->orderBy('entry_id')
            ->chunk((int) $this->option('chunk'), function ($rows) use (
                $season, $schools, $countries, $levels, $renumbered, $now, $dryRun, &$counts, &$quarantine, $bar,
                $venuesByName, $categoryByLegacySchool, $levelByGrade
            ) {
                $write = [];

                foreach ($rows as $s) {
                    $entryId = (int) $s->entry_id;
                    $number = $renumbered[$entryId] ?? trim((string) $s->student_id);

                    // Country first: both fallbacks below are scoped by it, and
                    // nothing in this roster is missing one.
                    $countryId = $countries[(int) $s->country_id] ?? null;
                    if ($countryId === null) {
                        $counts['no_country']++;
                        $this->quarantine($quarantine, $number, 'country '.$s->country_id.' not mapped');

                        continue;
                    }

                    $schoolId = $schools[(int) $s->school_id] ?? null;
                    if ($schoolId === null) {
                        // The venue they typed in, matched by name inside their
                        // own country. 35 Mongolian competitors carry no
                        // `school_id` at all while naming a venue that is in the
                        // register and already has a roster — a gap in the legacy
                        // form, not a competitor who sat nowhere.
                        $schoolId = $venuesByName[$countryId.'|'.mb_strtolower(trim((string) $s->school_external))] ?? null;
                        if ($schoolId !== null) {
                            $counts['school_by_name']++;
                        }
                    }
                    if ($schoolId === null) {
                        $counts['no_school']++;
                        $this->quarantine($quarantine, $number, 'no school (legacy school_id '.($s->school_id ?: 'empty').')');

                        continue;
                    }

                    $levelId = $levels[(int) $s->level] ?? null;
                    if ($levelId === null) {
                        // No level recorded, but the grade is — and a grade names
                        // exactly one level once the scheme is known. The scheme
                        // is the one the rest of that venue sits under.
                        $category = $categoryByLegacySchool[(int) $s->school_id] ?? null;
                        $grade = is_numeric($s->class) ? (int) $s->class : null;
                        if ($category !== null && $grade !== null) {
                            $levelId = $levelByGrade[$category.'|'.$grade] ?? null;
                        }
                        if ($levelId !== null) {
                            $counts['level_by_grade']++;
                        }
                    }
                    if ($levelId === null) {
                        $counts['no_level']++;
                        $this->quarantine($quarantine, $number, 'level '.($s->level ?: 'empty').' not mapped');

                        continue;
                    }

                    $born = $this->birthDate((string) $s->date_of_birth);
                    if ($born === null && trim((string) $s->date_of_birth) !== '') {
                        $counts['bad_date']++;
                    }

                    $write[] = [
                        'season_id' => $season->id,
                        'competitor_number' => $number,
                        // The number is the round followed by six digits, so the
                        // sequence is what is left once the round is taken off.
                        'sequence' => (int) substr($number, strlen((string) $season->round_number)),
                        'school_id' => $schoolId,
                        // The competitor's own school, typed in beside the venue
                        // they sat at. 84% of this roster carries both.
                        'school_external' => $this->trimmed($s->school_external),
                        'country_id' => $countryId,
                        'difficulty_level_id' => $levelId,
                        'name' => trim((string) $s->name),
                        'date_of_birth' => $born,
                        'grade' => is_numeric($s->class) ? (int) $s->class : null,
                        'status' => 'active',
                        'attendance' => ((int) $s->absent === 1) ? 'absent' : 'present',
                        'legacy_id' => $entryId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (! $dryRun && $write !== []) {
                    DB::table('registrations')->upsert($write, ['competitor_number'], [
                        'season_id', 'sequence', 'school_id', 'school_external', 'country_id',
                        'difficulty_level_id', 'name', 'date_of_birth', 'grade', 'attendance',
                        'legacy_id', 'updated_at',
                    ]);
                }

                $counts['written'] += count($write);
                $bar->advance($rows->count());
            });

        $bar->finish();
        $this->newLine(2);

        $this->report($counts, $renumbered, $quarantine, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Registrations that carry no legacy id were made here — the synthetic load
     * test roster, or anything typed in. They occupy the same competitor numbers
     * the legacy roster is about to claim, so they cannot simply be left.
     */
    private function clearLocalRoster(Season $season, bool $dryRun): bool
    {
        $local = DB::table('registrations')
            ->where('season_id', $season->id)
            ->whereNull('legacy_id')
            ->count();

        if ($local === 0) {
            return true;
        }

        if (! $this->option('replace-local')) {
            $this->error("{$local} registrations in this season carry no legacy id.");
            $this->line('They hold the competitor numbers this import needs. Re-run with --replace-local to delete');
            $this->line('them and everything hanging off them (attempts, answers, results), or clear them yourself.');

            return false;
        }

        $this->warn("Deleting {$local} locally-made registrations and their attempts, answers and results.");

        if ($dryRun) {
            return true;
        }

        DB::transaction(function () use ($season) {
            $ids = DB::table('registrations')
                ->where('season_id', $season->id)
                ->whereNull('legacy_id')
                ->pluck('id');

            foreach ($ids->chunk(2000) as $chunk) {
                $attempts = DB::table('attempts')->whereIn('registration_id', $chunk)->pluck('id');
                foreach ($attempts->chunk(2000) as $attemptChunk) {
                    DB::table('attempt_answers')->whereIn('attempt_id', $attemptChunk)->delete();
                }
                DB::table('attempts')->whereIn('registration_id', $chunk)->delete();
                DB::table('registration_results')->whereIn('registration_id', $chunk)->delete();
                DB::table('registration_qualifications')->whereIn('registration_id', $chunk)->delete();
                DB::table('registrations')->whereIn('id', $chunk)->delete();
            }
        });

        return true;
    }

    /**
     * OD-8: the legacy number generator raced and handed the same competitor
     * number to two different children. Both stay. The row created first — the
     * lower `entry_id`, the one carrying the results — keeps the number it has
     * been identifying with; the later one is given the first number never
     * issued. Results follow the child rather than the number, so nothing moves
     * with it.
     *
     * @return array<int, string> legacy entry_id => the number to write
     */
    /**
     * `country|lower(venue name)` → our school id, for names that are theirs
     * alone in that country.
     *
     * 🪤 Ambiguous names are left out rather than guessed at. Putting a
     * competitor in the wrong venue is worse than leaving them out: the venue is
     * who marks them, who sees them on a register, and who their results are
     * counted under.
     *
     * @return array<string, int>
     */
    private function venuesByName(): array
    {
        $seen = [];

        foreach (DB::table('schools')->where('status', 'active')->get(['id', 'name', 'country_id']) as $school) {
            $key = $school->country_id.'|'.mb_strtolower(trim((string) $school->name));
            $seen[$key][] = (int) $school->id;
        }

        return array_map(
            fn (array $ids): int => $ids[0],
            array_filter($seen, fn (array $ids): bool => count($ids) === 1)
        );
    }

    /**
     * Legacy school id → the legacy difficulty category its roster sits under.
     *
     * A country uses one scheme, and a venue follows its country. Read from the
     * legacy rows rather than from ours, because during a `--replace-local` run
     * ours are not written yet. The majority decides: the odd row entered under
     * another scheme does not move the venue.
     *
     * @return array<int, int>
     */
    private function categoryByLegacySchool(Connection $legacy): array
    {
        $best = [];
        $rows = $legacy->table('el_student as s')
            ->join('difficulty_category_levels as dcl', 'dcl.id', '=', 's.level')
            ->whereNotNull('s.school_id')
            ->groupBy('s.school_id', 'dcl.difficulty_category_id')
            ->selectRaw('s.school_id, dcl.difficulty_category_id as category, count(*) as n')
            ->get();

        foreach ($rows as $row) {
            $schoolId = (int) $row->school_id;
            if (! isset($best[$schoolId]) || $row->n > $best[$schoolId]['n']) {
                $best[$schoolId] = ['category' => (int) $row->category, 'n' => (int) $row->n];
            }
        }

        return array_map(fn (array $row): int => $row['category'], $best);
    }

    /**
     * `legacy category id|grade` → our difficulty level id.
     *
     * The grades a level covers are the level's own definition, so a grade names
     * exactly one level once the scheme is known — which is how a coordinator
     * fills the form in the first place.
     *
     * @return array<string, int>
     */
    private function levelByGrade(): array
    {
        $map = [];

        $levels = DB::table('difficulty_levels as dl')
            ->join('difficulty_categories as dc', 'dc.id', '=', 'dl.difficulty_category_id')
            ->whereNotNull('dc.legacy_id')
            ->get(['dl.id', 'dl.grades', 'dc.legacy_id as category']);

        foreach ($levels as $level) {
            foreach ((array) json_decode((string) $level->grades, true) as $grade) {
                $map[$level->category.'|'.(int) $grade] = (int) $level->id;
            }
        }

        return $map;
    }

    private function renumberDuplicates(Connection $legacy, Season $season): array
    {
        $duplicated = $legacy->table('el_student')
            ->select('student_id')
            ->groupBy('student_id')
            ->havingRaw('count(*) > 1')
            ->pluck('student_id');

        if ($duplicated->isEmpty()) {
            return [];
        }

        $next = 1 + (int) $legacy->table('el_student')->max(DB::raw('cast(student_id as unsigned)'));
        $renumbered = [];

        foreach ($duplicated as $number) {
            $rows = $legacy->table('el_student')
                ->where('student_id', $number)
                ->orderBy('entry_id')
                ->pluck('entry_id');

            // The first keeps it; every one after is issued a new number.
            foreach ($rows->skip(1) as $entryId) {
                $renumbered[(int) $entryId] = (string) $next++;
            }
        }

        $this->warn(count($renumbered).' duplicated competitor numbers renumbered (OD-8).');

        return $renumbered;
    }

    /** 🪤 Legacy stores the date as text, and this roster is uniformly d.m.Y. */
    private function birthDate(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('d.m.Y', $raw);
        } catch (\Throwable) {
            return null;
        }

        return $date === false ? null : $date->format('Y-m-d');
    }

    private function trimmed(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @param  list<string>  $quarantine */
    private function quarantine(array &$quarantine, string $number, string $why): void
    {
        if (count($quarantine) < 100) {
            $quarantine[] = $number.' — '.$why;
        }
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<int, string>  $renumbered
     * @param  list<string>  $quarantine
     */
    private function report(array $counts, array $renumbered, array $quarantine, bool $dryRun): void
    {
        $verb = $dryRun ? 'Would import' : 'Imported';
        $this->info("{$verb} {$counts['written']} registrations.");

        foreach ([
            'no_school' => 'no school of any kind (kept out: a registration must have one)',
            'no_level' => 'level not resolvable',
            'no_country' => 'country not mapped',
        ] as $key => $why) {
            if ($counts[$key] > 0) {
                $this->line("Skipped {$counts[$key]}: {$why}.");
            }
        }

        foreach ([
            'school_by_name' => 'venue matched by the name the competitor typed in',
            'level_by_grade' => 'level read from the grade, under the scheme their venue uses',
        ] as $key => $how) {
            if ($counts[$key] > 0) {
                $this->line("Recovered {$counts[$key]}: {$how}.");
            }
        }

        if ($counts['bad_date'] > 0) {
            $this->line("Unreadable birth dates left empty: {$counts['bad_date']}.");
        }

        if ($renumbered !== []) {
            $this->line('Renumbered (OD-8): '.count($renumbered).'.');
        }

        if ($quarantine !== []) {
            $this->newLine();
            $this->line('Not imported — these are numbers, never names:');
            foreach ($quarantine as $line) {
                $this->line('  '.$line);
            }
        }
    }
}
