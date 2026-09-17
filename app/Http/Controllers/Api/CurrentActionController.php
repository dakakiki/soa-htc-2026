<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Console\Commands\FinalizeExpiredAttempts;
use App\Domain\Competition\Models\Attempt;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monitoring → Current action: who is sitting an exam right now.
 *
 * 🔴 The shape is decided by one measurement, not by taste. The legacy roster's
 * busiest minute carried 6.607 results — around 110 a second — and a page that
 * is a list of rows is unreadable at that rate: a row is gone before it can be
 * read. So the counts come first and the list second, capped, with the ones
 * closest to their deadline at the top.
 *
 * 🪤 An attempt past its deadline but still `in_progress` is not somebody
 * working. It is a browser that was closed or a connection that dropped, waiting
 * for {@see FinalizeExpiredAttempts} to close it. It is
 * counted apart, because it is the row an invigilator would want to be told
 * about and it would otherwise sit in "working now" forever.
 *
 * 🔴 Administrators only, the same reasoning as the results archive and the user
 * log: this is the whole world's competitors, and nothing here can be narrowed
 * to a coordinator's own venues honestly.
 */
class CurrentActionController extends Controller
{
    /** How many rows the list carries. The counts above it cover the rest. */
    private const LIST_CAP = 100;

    /** Submissions inside this window make the "still coming in" number. */
    private const RECENT_MINUTES = 10;

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('reports.view');
        $this->assertGlobalScope($request);

        $now = now();
        $deadline = $now->copy()->subSeconds(Attempt::SUBMIT_GRACE_SECONDS);

        // Qualified: the venues count joins `registrations`, which has a `status` too.
        $open = fn () => Attempt::query()->where('attempts.status', 'in_progress');

        $running = (clone $open())->where('attempts.expires_at', '>=', $deadline);
        $overdue = (clone $open())->where('attempts.expires_at', '<', $deadline);

        return response()->json(['data' => [
            'as_of' => $now->toIso8601String(),
            'counts' => [
                'running' => (clone $running)->count(),
                'overdue' => (clone $overdue)->count(),
                'submitted_recently' => Attempt::query()
                    ->where('attempts.status', '!=', 'void')
                    ->where('attempts.submitted_at', '>=', $now->copy()->subMinutes(self::RECENT_MINUTES))
                    ->count(),
                'venues' => (clone $open())
                    ->join('registrations as r', 'r.id', '=', 'attempts.registration_id')
                    ->distinct()
                    ->count('r.school_id'),
                'recent_minutes' => self::RECENT_MINUTES,
            ],
            'by_exam' => $this->byExam(),
            'rows' => $this->rows($request, $deadline),
        ]]);
    }

    /**
     * Open attempts grouped by the test they are on, busiest first.
     *
     * This is the part that stays readable when the list cannot: during a real
     * exam almost every open attempt belongs to a handful of tests, and knowing
     * which of them is carrying the weight is the useful thing.
     *
     * @return list<array<string, mixed>>
     */
    private function byExam(): array
    {
        return DB::table('attempts')
            ->join('tests as t', 't.id', '=', 'attempts.test_id')
            ->where('attempts.status', 'in_progress')
            ->groupBy('t.id', 't.title')
            ->orderByDesc(DB::raw('count(*)'))
            ->limit(20)
            ->get(['t.id as test_id', 't.title as test', DB::raw('count(*) as n')])
            ->map(fn (object $r): array => [
                'test_id' => (int) $r->test_id,
                'test' => $r->test,
                'n' => (int) $r->n,
            ])
            ->all();
    }

    /**
     * The open attempts themselves, the closest to running out first.
     *
     * 🪤 Ordered by `expires_at` ascending rather than by when they started: what
     * an invigilator needs is who is about to lose their chance, and an attempt
     * already past its deadline sorts to the very top on its own.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Request $request, Carbon $deadline): array
    {
        return Attempt::query()
            ->where('attempts.status', 'in_progress')
            ->join('registrations as r', 'r.id', '=', 'attempts.registration_id')
            ->leftJoin('countries as c', 'c.id', '=', 'r.country_id')
            ->leftJoin('schools as s', 's.id', '=', 'r.school_id')
            ->leftJoin('difficulty_levels as dl', 'dl.id', '=', 'r.difficulty_level_id')
            ->leftJoin('tests as t', 't.id', '=', 'attempts.test_id')
            ->leftJoin('quizzes as qz', 'qz.id', '=', 'attempts.quiz_id')
            // One box for the call that actually comes in: "competitor X has a problem".
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.$request->string('q')->toString().'%';
                $query->where(function ($w) use ($term): void {
                    $w->where('r.competitor_number', 'like', $term)->orWhere('r.name', 'like', $term);
                });
            })
            /*
             * 🪤 The columns go in `select()`, not in `get()`. `withCount()` sets a
             * select of its own (`attempts.*`), and a column list handed to `get()`
             * is only honoured when nothing has selected yet — so it was silently
             * dropped and every joined field arrived null.
             */
            ->select([
                'attempts.id', 'attempts.started_at', 'attempts.expires_at', 'attempts.registration_id',
                'r.competitor_number', 'r.name as student_name', 'c.name as country', 's.name as venue',
                'dl.level_short as level', 't.title as test', 'qz.title as quiz',
            ])
            ->withCount('answers')
            ->orderBy('attempts.expires_at')
            ->limit(self::LIST_CAP)
            ->get()
            ->map(fn (Attempt $a): array => [
                'id' => $a->id,
                'registration_id' => $a->registration_id,
                'competitor_number' => $a->competitor_number,
                'name' => $a->student_name,
                'country' => $a->country,
                'venue' => $a->venue,
                'level' => $a->level,
                'quiz' => $a->quiz,
                'test' => $a->test,
                'started_at' => $a->started_at?->toIso8601String(),
                'expires_at' => $a->expires_at?->toIso8601String(),
                'answered' => $a->answers_count,
                // Decided here rather than in the browser: the server's clock is the
                // one the deadline was written against.
                'overdue' => $a->expires_at !== null && $a->expires_at->lt($deadline),
            ])
            ->all();
    }

    private function assertGlobalScope(Request $request): void
    {
        abort_if(
            $request->user()?->allowedSchoolIds() !== null,
            Response::HTTP_FORBIDDEN,
            'Current action covers every venue at once, so it is open to administrators only.',
        );
    }
}
