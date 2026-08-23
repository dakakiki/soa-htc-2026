<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Support\AttendanceImporter;
use App\Domain\Competition\Support\AttendanceReport;
use App\Domain\Competition\Support\RegistrationExporter;
use App\Domain\Competition\Support\RegistrationImporter;
use App\Domain\Competition\Support\RegistrationResults;
use App\Domain\Competition\Support\SoaCertificate;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRegistrationRequest;
use App\Http\Requests\UpdateRegistrationRequest;
use App\Http\Resources\RegistrationResource;
use App\Support\PdfWriter;
use App\Support\XlsxReader;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
     * The "SOA Cert" participation certificates for one venue as a single PDF —
     * one page per student in the scope, with their points for the chosen round
     * ({@see SoaCertificate}). Same venue + level scope and coordinator guard as
     * the attendance register; the round (Preliminary / National) picks which two
     * marks print. Returns 422 when the scope holds no students.
     */
    /**
     * One chunk of the "SOA Cert" participation certificates for a venue as a PDF.
     * mPDF renders every page (~0.3s), so a large venue is split into fixed-size
     * chunks — exactly what the legacy app did (it used DomPDF, slower still). The
     * client reads the plan ({@see soaCertificatePlan()}) then downloads each part
     * in turn, keeping every request bounded. `chunk` is the zero-based part index
     * (default 0). Same venue + level scope and coordinator guard as the attendance
     * register; the round picks which two marks print.
     */
    public function soaCertificate(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('viewAny', Registration::class);

        $validated = $request->validate($this->certRules() + [
            'chunk' => ['sometimes', 'integer', 'min:0'],
        ]);

        $schoolId = (int) $validated['school_id'];
        $this->assertVenueInScope($request, $schoolId);

        $size = $this->certChunkSize();
        $chunk = max(0, (int) ($validated['chunk'] ?? 0));

        // Building every page is cheap string work; only this part is rendered by mPDF.
        $slice = array_slice(
            SoaCertificate::pages($schoolId, $validated['level_id'], $validated['round']),
            $chunk * $size,
            $size,
        );
        if ($slice === []) {
            return response()->json(['message' => __('No students match the selected venue and levels.')], 422);
        }

        $part = str_pad((string) ($chunk + 1), 2, '0', STR_PAD_LEFT);
        $filename = 'SOA_Cert_'.ucfirst($validated['round']).'_'.now()->format('Y-m-d').'_part'.$part.'.pdf';

        return response(PdfWriter::fromPages($slice, '', 'P', SoaCertificate::styleHead(), plain: true), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * The chunk plan for a SOA Cert run: how many students match and how many parts
     * the client must download (legacy chunking). The count is round-independent.
     */
    public function soaCertificatePlan(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Registration::class);

        $validated = $request->validate($this->certRules());
        $schoolId = (int) $validated['school_id'];
        $this->assertVenueInScope($request, $schoolId);

        $total = SoaCertificate::count($schoolId, $validated['level_id']);
        $size = $this->certChunkSize();

        return response()->json([
            'total' => $total,
            'chunk_size' => $size,
            'chunks' => (int) ceil($total / $size),
        ]);
    }

    /** Shared validation for the SOA Cert plan + chunk endpoints. */
    private function certRules(): array
    {
        return [
            'round' => ['required', 'string', Rule::in(SoaCertificate::rounds())],
            'school_id' => ['required', 'integer', 'exists:schools,id'],
            'level_id' => ['required', 'array', 'min:1'],
            'level_id.*' => ['integer', 'exists:difficulty_levels,id'],
        ];
    }

    /** Certificates per chunk (matches the legacy app's 50); keeps each render bounded. */
    private function certChunkSize(): int
    {
        return max(1, (int) (config('cert.chunk') ?: 50));
    }

    /**
     * The "Upload Students" import template (.xlsx): the column headers plus the
     * instructions row, matching the legacy file. Downloaded from the import modal.
     */
    public function importTemplate(): Response
    {
        $this->authorize('students.bulk');

        $headers = ['Name', 'Date Of Birth', 'School (if different from venue)', 'Grade', 'Category'];
        $hint = [
            'use just standard Latin letters without country specific',
            'dd.mm.yyyy',
            '',
            'school grade',
            'Please enter in this format: for Baby Hippo enter BH, Little Hippo enter LH, for Hippo 1 enter H1, for Hippo 2 enter H2, … for Hippo S1 enter S1, etc.',
        ];

        return response(XlsxWriter::toString($headers, [$hint], 'Students'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="students-import-template.xlsx"',
        ]);
    }

    /**
     * The difficulty-category sets a competitor at this country can be imported
     * into: every regular category that applies (all-countries, or linked to the
     * country). The chosen set fixes how a category short (BH, S1, …) resolves.
     */
    public function importCategorySets(Request $request): JsonResponse
    {
        $this->authorize('students.bulk');

        $validated = $request->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id'],
        ]);
        $countryId = (int) $validated['country_id'];

        $sets = DifficultyCategory::query()
            ->where('type', 'regular')
            ->where('status', 'active')
            ->where(fn ($q) => $q->where('countries_all', true)
                ->orWhereHas('countries', fn ($c) => $c->whereKey($countryId)))
            ->orderByDesc('countries_all')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'label' => (string) $c->name]);

        return response()->json(['data' => $sets]);
    }

    /**
     * Bulk-create students for one venue from an uploaded .xlsx (the "Upload
     * Students" flow, {@see RegistrationImporter}). The category set fixes how the
     * short codes resolve. Returns {created} on success, or {created:0, errors[]}
     * (HTTP 422) listing the offending rows when the file has any invalid row.
     */
    public function import(Request $request): JsonResponse
    {
        $this->authorize('students.bulk');
        $this->authorize('create', Registration::class);

        $validated = $request->validate([
            'school_id' => ['required', 'integer', 'exists:schools,id'],
            'category_id' => ['required', 'integer', 'exists:difficulty_categories,id'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $schoolId = (int) $validated['school_id'];
        $this->assertVenueInScope($request, $schoolId);

        try {
            $rows = XlsxReader::read((string) $validated['file']->getRealPath());
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['file' => __('The file could not be read. Upload a valid .xlsx.')]);
        }

        $summary = RegistrationImporter::import($schoolId, (int) $validated['category_id'], $rows);

        return response()->json($summary, $summary['error_count'] === 0 ? 200 : 422);
    }

    /**
     * The uploaded student file returned with an "Error" column filled in per invalid
     * row ({@see RegistrationImporter::errorReport()}) — the user fixes it and
     * re-uploads. Same venue scope + validation as {@see import()}.
     */
    public function importErrors(Request $request): Response
    {
        $this->authorize('students.bulk');

        $validated = $request->validate([
            'school_id' => ['required', 'integer', 'exists:schools,id'],
            'category_id' => ['required', 'integer', 'exists:difficulty_categories,id'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $this->assertVenueInScope($request, (int) $validated['school_id']);

        try {
            $rows = XlsxReader::read((string) $validated['file']->getRealPath());
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['file' => __('The file could not be read. Upload a valid .xlsx.')]);
        }

        $xlsx = RegistrationImporter::errorReport((int) $validated['school_id'], (int) $validated['category_id'], $rows);

        return response($xlsx, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="students-import-errors.xlsx"',
        ]);
    }

    /** The attendance-update template (.xlsx): Candidate no | Absent (0/1). */
    public function attendanceImportTemplate(): Response
    {
        $this->authorize('students.bulk');

        return response(XlsxWriter::toString(['Candidate no', 'Absent'], [['10000000', '0/1']], 'Attendance'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="attendance-update-template.xlsx"',
        ]);
    }

    /**
     * Bulk-update attendance from an uploaded .xlsx (Candidate no | Absent) — the
     * separate "update students" flow ({@see AttendanceImporter}). A non-global
     * coordinator only affects their own venues. Returns per-outcome counts.
     */
    public function attendanceImport(Request $request): JsonResponse
    {
        $this->authorize('students.bulk');
        abort_unless($request->user()?->can_student_edit ?? false, 403);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        try {
            $rows = XlsxReader::read((string) $validated['file']->getRealPath());
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['file' => __('The file could not be read. Upload a valid .xlsx.')]);
        }

        $summary = AttendanceImporter::import($rows, $request->user()->allowedSchoolIds());

        return response()->json($summary);
    }

    /** A non-global coordinator may only act on their own venues. */
    private function assertVenueInScope(Request $request, int $schoolId): void
    {
        $allowed = $request->user()->allowedSchoolIds();
        if ($allowed !== null && ! $allowed->contains($schoolId)) {
            abort(403);
        }
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
