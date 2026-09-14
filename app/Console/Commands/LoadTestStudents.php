<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Models\StudentSession;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Support\SeasonContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Synthetic competitors and their sessions, so a load test can open real exams
 * without a real child's row being touched — and can be undone exactly.
 *
 * 🔴 Everything it creates carries TWO markers: the venue is named after the
 * tag, and every competitor number starts with {@see self::NUMBER_PREFIX}, a
 * block no real season issues (a real number opens with the round, `14…`).
 * `--cleanup` deletes only rows carrying both, which is why it can be run
 * against a server that holds real names.
 *
 * 🪤 It does NOT go through `POST /api/student/identify`, and that is the point:
 * identification is capped at eight a minute per IP (a deliberate guard against
 * a date-of-birth sweep), so five thousand logins from one address is not a load
 * test but a two-week wait. The exam engine is what is being measured; the login
 * is measured separately, in small bursts.
 */
class LoadTestStudents extends Command
{
    /** Competitor numbers start here — eight characters, outside any round's block. */
    private const NUMBER_PREFIX = '99';

    /**
     * 🪤 `sequence` is unique per season, and a real season counts from 1 — so the
     * synthetic rows start far above the roster rather than colliding with it on
     * the first insert.
     */
    private const SEQUENCE_BASE = 9_000_000;

    protected $signature = 'loadtest:students
        {--count=100 : How many synthetic competitors to create}
        {--test= : The test they should be able to sit (defaults to the first active one)}
        {--country=RS : ISO code of the country they are registered in}
        {--tag=loadtest : Marker for the venue and the output file}
        {--cleanup : Delete everything this tag created, and nothing else}
        {--reset : Delete only their attempts, so the same competitors can sit the test again}
        {--force : Required to CREATE rows on a production environment}';

    protected $description = 'Create (or remove) synthetic competitors with live sessions, for load testing the exam engine';

    public function handle(): int
    {
        $tag = (string) $this->option('tag');

        if ($this->option('cleanup')) {
            return $this->cleanup($tag);
        }

        if ($this->option('reset')) {
            return $this->reset($tag);
        }

        /*
         * Only creation is guarded. Removing and resetting touch nothing but rows
         * carrying both markers — that is the whole design — and a guard that
         * makes the undo harder than the do is a guard pointing the wrong way.
         */
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('This writes rows on a production environment. Re-run with --force if that is what you mean.');

            return self::FAILURE;
        }

        return $this->create($tag);
    }

    /**
     * Clear the attempts and leave the competitors standing, so the same roomful
     * can sit the same test again.
     *
     * 🪤 A competitor sits a given test once: `start` resumes or refuses the
     * second time (ADR-0016). Without this, the second level of a ramp would be
     * measuring the refusal rather than the exam.
     */
    private function reset(string $tag): int
    {
        $registrations = $this->syntheticRegistrationIds($tag);

        if ($registrations->isEmpty()) {
            $this->info('Nothing to reset.');

            return self::SUCCESS;
        }

        $this->waitForGrading();

        $attempts = DB::table('attempts')->whereIn('registration_id', $registrations)->pluck('id');
        $answers = DB::table('attempt_answers')->whereIn('attempt_id', $attempts)->delete();
        $count = DB::table('attempts')->whereIn('id', $attempts)->delete();

        $this->info("Reset {$count} attempts and {$answers} answers; {$registrations->count()} competitors kept.");

        return self::SUCCESS;
    }

    /**
     * Let the queued grading finish before the attempts are taken away.
     *
     * 🔴 Measured the hard way (2026-09-14): a contest attempt's grading is
     * queued, not done in the request (ADR-0082), and resetting between the
     * levels of a ramp pulled 1.849 attempts out from under jobs that were still
     * waiting for them. Every one of them landed in `failed_jobs` as "no query
     * results for model [Attempt]" — a mess made by the measuring, not by the
     * thing being measured, and one that looks exactly like a real failure the
     * next morning.
     */
    private function waitForGrading(int $seconds = 120): void
    {
        $pending = fn (): int => DB::table('jobs')->where('payload', 'like', '%GradeAttempt%')->count();

        if (($left = $pending()) === 0) {
            return;
        }

        $this->line("Waiting for {$left} queued gradings to finish…");

        for ($waited = 0; $waited < $seconds; $waited++) {
            sleep(1);

            if (($left = $pending()) === 0) {
                return;
            }
        }

        $this->warn("{$left} gradings are still queued and will fail when their attempts go. Is the queue worker running?");
    }

    /**
     * The registrations carrying BOTH markers — the tag's venue and the
     * synthetic number block. Everything destructive goes through here.
     *
     * @return Collection<int, int>
     */
    private function syntheticRegistrationIds(string $tag)
    {
        $venue = School::query()->where('name', $this->venueName($tag))->first();

        if ($venue === null) {
            return collect();
        }

        return Registration::query()
            ->where('school_id', $venue->id)
            ->where('competitor_number', 'like', self::NUMBER_PREFIX.'%')
            ->pluck('id');
    }

    private function create(string $tag): int
    {
        $count = max(1, (int) $this->option('count'));
        $season = SeasonContext::active();

        if ($season === null) {
            $this->error('No active season — a registration has nowhere to go.');

            return self::FAILURE;
        }

        $test = $this->option('test')
            ? Test::query()->findOrFail((int) $this->option('test'))
            : Test::query()->where('status', 'active')->orderBy('id')->firstOrFail();

        $level = $test->levels()->first();
        if ($level === null) {
            $this->error("Test {$test->id} carries no difficulty level, so no registration can be aimed at it.");

            return self::FAILURE;
        }

        // 🪤 Two code columns: `code` is the three-letter one (SRB) and
        // `iso_alpha2` the two-letter one (RS). Either spelling is accepted.
        $code = strtoupper((string) $this->option('country'));
        $country = Country::query()
            ->where(fn ($q) => $q->where('code', $code)->orWhere('iso_alpha2', $code))
            ->firstOrFail();

        $venue = School::query()->firstOrCreate(
            ['name' => $this->venueName($tag)],
            ['country_id' => $country->id, 'status' => 'active'],
        );

        // Start above whatever this tag left behind, so a second run does not
        // collide with the first one's numbers.
        $taken = Registration::query()
            ->where('competitor_number', 'like', self::NUMBER_PREFIX.'%')
            ->max('competitor_number');
        $next = $taken === null ? 1 : ((int) substr((string) $taken, 2)) + 1;

        $now = now();
        $rows = [];
        $sessions = [];
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $number = self::NUMBER_PREFIX.str_pad((string) ($next + $i), 6, '0', STR_PAD_LEFT);
            $token = Str::random(64);

            $rows[] = [
                'season_id' => $season->id,
                'competitor_number' => $number,
                'sequence' => self::SEQUENCE_BASE + $next + $i,
                'school_id' => $venue->id,
                'country_id' => $country->id,
                'difficulty_level_id' => $level->id,
                'name' => 'Load Test '.($next + $i),
                'date_of_birth' => '2010-01-01',
                'grade' => 6,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $out[] = ['competitor_number' => $number, 'token' => $token];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Registration::query()->insert($chunk);
        }

        $ids = Registration::query()
            ->where('school_id', $venue->id)
            ->whereIn('competitor_number', array_column($out, 'competitor_number'))
            ->pluck('id', 'competitor_number');

        foreach ($out as $index => $entry) {
            $out[$index]['registration_id'] = (int) $ids[$entry['competitor_number']];
            $sessions[] = [
                'registration_id' => (int) $ids[$entry['competitor_number']],
                'token_hash' => hash('sha256', $entry['token']),
                'expires_at' => $now->copy()->addMinutes(StudentSession::LIFETIME_MINUTES),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'loadtest',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($sessions, 500) as $chunk) {
            StudentSession::query()->insert($chunk);
        }

        /*
         * 🪤 Every quiz carries a password, and unlocking it is capped at eight a
         * minute — so a thousand competitors cannot knock on that door at once.
         * The sessions are handed the unlock here, because what is being measured
         * is the exam engine and not the password form. The gate itself is a
         * separate, small measurement.
         */
        $quizIds = DB::table('exam_test')
            ->join('exam_quiz', 'exam_quiz.exam_id', '=', 'exam_test.exam_id')
            ->where('exam_test.test_id', $test->id)
            ->distinct()
            ->pluck('exam_quiz.quiz_id');

        $sessionIds = StudentSession::query()
            ->whereIn('registration_id', array_column($out, 'registration_id'))
            ->pluck('id');

        $unlocks = [];
        foreach ($sessionIds as $sessionId) {
            foreach ($quizIds as $quizId) {
                $unlocks[] = ['student_session_id' => $sessionId, 'quiz_id' => $quizId, 'unlocked_at' => $now];
            }
        }

        foreach (array_chunk($unlocks, 1000) as $chunk) {
            DB::table('student_session_quiz')->insert($chunk);
        }

        $path = storage_path('app/loadtest');
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        $file = $path.DIRECTORY_SEPARATOR.$tag.'.json';
        file_put_contents($file, json_encode([
            'tag' => $tag,
            'test_id' => $test->id,
            'test_title' => $test->title,
            'quiz_id' => $quizIds->first(),
            'venue_id' => $venue->id,
            'season_id' => $season->id,
            'level' => $level->level_short,
            'students' => $out,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("{$count} competitors at {$level->level_short}, venue #{$venue->id}, for test #{$test->id} ({$test->title}).");
        $this->line("Sessions written to {$file}");

        return self::SUCCESS;
    }

    /**
     * Undo, by the two markers and nothing else: the tag's venue, and only the
     * registrations inside it whose number sits in the synthetic block.
     */
    private function cleanup(string $tag): int
    {
        $venue = School::query()->where('name', $this->venueName($tag))->first();

        if ($venue === null) {
            $this->info("Nothing to remove: no venue named {$this->venueName($tag)}.");

            return self::SUCCESS;
        }

        $registrations = $this->syntheticRegistrationIds($tag);

        $this->waitForGrading();

        $attempts = DB::table('attempts')->whereIn('registration_id', $registrations)->pluck('id');

        $answers = DB::table('attempt_answers')->whereIn('attempt_id', $attempts)->delete();
        $attemptCount = DB::table('attempts')->whereIn('id', $attempts)->delete();
        DB::table('registration_qualifications')->whereIn('registration_id', $registrations)->delete();
        DB::table('registration_results')->whereIn('registration_id', $registrations)->delete();
        $sessionIds = StudentSession::query()->whereIn('registration_id', $registrations)->pluck('id');
        DB::table('student_session_quiz')->whereIn('student_session_id', $sessionIds)->delete();
        $sessionCount = StudentSession::query()->whereIn('registration_id', $registrations)->delete();
        $registrationCount = Registration::query()->whereIn('id', $registrations)->delete();

        // The venue goes only if nothing real wandered into it.
        $left = Registration::query()->where('school_id', $venue->id)->count();
        if ($left === 0) {
            $venue->delete();
        }

        $this->info("Removed {$registrationCount} competitors, {$sessionCount} sessions, {$attemptCount} attempts, {$answers} answers.");
        if ($left > 0) {
            $this->warn("Venue #{$venue->id} kept: {$left} registration(s) in it are not this test's.");
        }

        return self::SUCCESS;
    }

    private function venueName(string $tag): string
    {
        return strtoupper($tag).' — synthetic venue';
    }
}
