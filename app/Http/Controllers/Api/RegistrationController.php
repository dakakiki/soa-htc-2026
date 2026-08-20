<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Support\AttendanceReport;
use App\Domain\Competition\Support\RegistrationExporter;
use App\Domain\Competition\Support\RegistrationResults;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRegistrationRequest;
use App\Http\Requests\UpdateRegistrationRequest;
use App\Http\Resources\RegistrationResource;
use App\Support\PdfWriter;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class RegistrationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Registration::class);

        $query = Registration::query()->with(['school', 'country', 'level'])->latest('id');
        $this->applyFilters($query, $request);

        $perPage = min(max($request->integer('per_page', 20), 1), 200);
        $paginated = $query->paginate($perPage);

        // Attach each competitor's published per-round scores for the results grid.
        $results = RegistrationResults::forRegistrations($paginated->pluck('id')->all());
        $paginated->getCollection()->each(function (Registration $reg) use ($results): void {
            $reg->results_grid = $results[$reg->id] ?? [];
        });

        return RegistrationResource::collection($paginated);
    }

    /**
     * Export the currently filtered students roster to .xlsx (legacy "Students
     * Export" layout). Same filters and coordinator scope as {@see index()}, but
     * the whole matching set rather than one page — so the file mirrors exactly
     * what the admin filtered to.
     */
    public function export(Request $request): Response
    {
        $this->authorize('viewAny', Registration::class);

        $query = Registration::query();
        $this->applyFilters($query, $request);
        $ids = $query->pluck('registrations.id')->all();

        [$headers, $rows] = RegistrationExporter::export($ids);

        $filename = now()->format('Y-m-d').'_Students_Export.xlsx';

        return response(XlsxWriter::toString($headers, $rows, 'Students'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * The printable attendance register (invigilation sheet) for one venue, as a
     * PDF (ADR: {@see AttendanceReport}). Scoped by venue + one or more difficulty
     * levels — no exam round. A coordinator may only print their own venues.
     * Returns 422 when the venue/levels hold no students (nothing to print).
     */
    public function attendanceReport(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('viewAny', Registration::class);

        $validated = $request->validate([
            'school_id' => ['required', 'integer', 'exists:schools,id'],
            'level_id' => ['required', 'array', 'min:1'],
            'level_id.*' => ['integer', 'exists:difficulty_levels,id'],
        ]);

        $schoolId = (int) $validated['school_id'];

        // A non-global coordinator may only print registers for their own venues.
        $allowed = $request->user()->allowedSchoolIds();
        if ($allowed !== null && ! $allowed->contains($schoolId)) {
            abort(403);
        }

        $html = AttendanceReport::html($schoolId, $validated['level_id']);
        if ($html === '') {
            return response()->json(['message' => __('No students match the selected venue and levels.')], 422);
        }

        $filename = 'Attendance_Report_'.now()->format('Y-m-d').'.pdf';

        return response(PdfWriter::toString($html, 'Attendance register', 'P'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Apply the list filters + row-level coordinator scope shared by {@see index()}
     * and {@see export()}: free-text search, geography, grade/level, status,
     * attendance, and round participation.
     *
     * @param  Builder<Registration>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        // Row-level scope: coordinators only see registrations for their schools.
        $allowed = $request->user()->allowedSchoolIds();
        if ($allowed !== null) {
            $query->whereIn('school_id', $allowed);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            if (ctype_digit($search)) {
                // Numbers search competitor_number by prefix, which uses its unique index.
                $query->where('competitor_number', 'like', $search.'%');
            } else {
                $query->where('name', 'like', '%'.$search.'%');
            }
        }
        if ($request->filled('school_id')) {
            $query->where('school_id', $request->integer('school_id'));
        }
        if ($request->filled('country_id')) {
            $query->where('country_id', $request->integer('country_id'));
        }
        if ($request->filled('region_id')) {
            $query->whereHas('school', fn ($s) => $s->where('region_id', $request->integer('region_id')));
        }
        if ($request->filled('level_id')) {
            $query->where('difficulty_level_id', $request->integer('level_id'));
        }
        if ($request->filled('grade')) {
            $query->where('grade', $request->integer('grade'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('attendance')) {
            $query->where('attendance', $request->string('attendance'));
        }
        // Exam round: competitors with an attempt on a test belonging to an exam
        // of that round (participation, mirroring the legacy per-round flags).
        if ($request->filled('exam_round_id')) {
            $round = $request->integer('exam_round_id');
            $query->whereIn('registrations.id', function ($sub) use ($round) {
                $sub->select('attempts.registration_id')
                    ->from('attempts')
                    ->join('exam_test', 'exam_test.test_id', '=', 'attempts.test_id')
                    ->join('exams', 'exams.id', '=', 'exam_test.exam_id')
                    ->where('exams.exam_round_id', $round);
            });
        }
    }

    /** The results-grid column definition (rounds and their test-type heads). */
    public function resultColumns(): JsonResponse
    {
        $this->authorize('viewAny', Registration::class);

        return response()->json(['data' => RegistrationResults::columns()]);
    }

    /** One competitor's published results, grouped by round, for the details modal. */
    public function results(Registration $registration): JsonResponse
    {
        $this->authorize('view', $registration);

        return response()->json(['data' => RegistrationResults::detail($registration->id)]);
    }

    public function show(Registration $registration): RegistrationResource
    {
        $this->authorize('view', $registration);

        return RegistrationResource::make($registration->load(['school', 'country', 'level']));
    }

    public function store(StoreRegistrationRequest $request): JsonResponse
    {
        $this->authorize('create', Registration::class);

        $registration = $this->createWithNumber($request->validated());

        return RegistrationResource::make($registration->load(['school', 'country', 'level']))
            ->response()->setStatusCode(201);
    }

    public function update(UpdateRegistrationRequest $request, Registration $registration): RegistrationResource
    {
        $this->authorize('update', $registration);

        $data = $request->validated();
        // Country stays derived from the school.
        if (isset($data['school_id'])) {
            $data['country_id'] = School::whereKey($data['school_id'])->value('country_id');
        }
        $registration->update($data);

        return RegistrationResource::make($registration->load(['school', 'country', 'level']));
    }

    public function destroy(Registration $registration): Response
    {
        $this->authorize('delete', $registration);

        $registration->delete();

        return response()->noContent();
    }

    /**
     * Create a registration with a freshly allocated competitor_number
     * (round_number + zero-padded per-season sequence). The sequence is
     * allocated under a row lock so concurrent inserts never collide.
     *
     * @param  array<string, mixed>  $data
     */
    private function createWithNumber(array $data): Registration
    {
        return DB::transaction(function () use ($data): Registration {
            $season = SeasonContext::active();

            $maxSequence = Registration::query()
                ->where('season_id', $season->id)
                ->lockForUpdate()
                ->max('sequence') ?? 0;
            $sequence = $maxSequence + 1;

            $data['season_id'] = $season->id;
            $data['sequence'] = $sequence;
            $data['competitor_number'] = $season->round_number.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
            $data['country_id'] = School::whereKey($data['school_id'])->value('country_id');
            $data['status'] ??= 'active';

            return Registration::create($data);
        });
    }
}
