<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\School;
use App\Domain\Organization\Support\SchoolExporter;
use App\Domain\Organization\Support\SchoolImporter;
use App\Domain\Organization\Support\VenueCompetitorCounts;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSchoolRequest;
use App\Http\Requests\UpdateSchoolRequest;
use App\Http\Resources\SchoolResource;
use App\Support\XlsxReader;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SchoolController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', School::class);

        $query = $this->filtered($request)->with(['country', 'region'])->orderBy('name');

        $perPage = min(max($request->integer('per_page', 20), 1), 200);
        $paginated = $query->paginate($perPage);

        // Competitor counts for the whole page in one query, so the BH…S5 columns
        // cost the same whether the page holds 10 venues or 200.
        $counts = VenueCompetitorCounts::for($paginated->pluck('id')->all());
        $paginated->getCollection()->each(function (School $school) use ($counts): void {
            $school->level_counts = $counts[$school->id] ?? [];
        });

        return SchoolResource::collection($paginated);
    }

    /**
     * Export the currently filtered venues to .xlsx (legacy "Venues Export"
     * layout). Same filters and coordinator scope as {@see index()}, but the
     * whole matching set rather than one page.
     */
    public function export(Request $request): Response
    {
        $this->authorize('viewAny', School::class);

        $ids = $this->filtered($request)->pluck('schools.id')->all();

        [$headers, $rows] = SchoolExporter::export($ids);

        $filename = now()->format('Y-m-d').'_Venues_Export.xlsx';

        return response(XlsxWriter::toString($headers, $rows, 'Venues'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * The venues import template (.xlsx): the editable columns plus one
     * instructions row (skipped on import). An exported file works too — the
     * importer matches columns by header name and ignores the computed ones.
     */
    public function importTemplate(): Response
    {
        $this->authorize('create', School::class);

        $headers = [
            'Venue ID', 'Venue', 'Country', 'Region', 'City', 'Address', 'Phone', 'Email',
            'No. Invigilators', 'Hours of English', 'Venue type', 'Status',
        ];
        $hint = [
            'blank = add new, filled = update that venue', 'Venue name', 'Serbia', 'Vojvodina (optional)',
            'Belgrade (optional)', '(optional)', '(optional)', '(optional)',
            'number (optional)', 'number (optional)', 'All categories / Only regular / Only special', 'active or inactive',
        ];

        return response(XlsxWriter::toString($headers, [$hint], 'Venues'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="venues-import-template.xlsx"',
        ]);
    }

    /**
     * Bulk create/update venues from an uploaded .xlsx ({@see SchoolImporter}).
     * Returns {created, updated} on success, or {error_count} (HTTP 422) when the
     * file has any invalid row — the annotated file comes from {@see importErrors()}.
     */
    public function import(Request $request): JsonResponse
    {
        $this->authorize('create', School::class);

        $summary = SchoolImporter::import($this->rowsFrom($request));

        return response()->json($summary, $summary['error_count'] === 0 ? 200 : 422);
    }

    /**
     * The uploaded venue file returned with an "Error" column filled in per
     * invalid row — the user fixes it and re-uploads.
     */
    public function importErrors(Request $request): Response
    {
        $this->authorize('create', School::class);

        return response(SchoolImporter::errorReport($this->rowsFrom($request)), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="venues-import-errors.xlsx"',
        ]);
    }

    /**
     * Validate the upload and parse it into worksheet rows.
     *
     * @return list<list<string>>
     */
    private function rowsFrom(Request $request): array
    {
        $validated = $request->validate(['file' => ['required', 'file', 'max:10240']]);

        try {
            return XlsxReader::read((string) $validated['file']->getRealPath());
        } catch (\RuntimeException) {
            throw ValidationException::withMessages(['file' => __('The file could not be read. Upload a valid .xlsx.')]);
        }
    }

    /**
     * The venue query with the list filters + row-level coordinator scope
     * applied — shared by {@see index()} and {@see export()}.
     */
    private function filtered(Request $request): Builder
    {
        $query = School::query();

        // Optional cascade filters (used by the assignment school picker).
        if ($request->filled('country_id')) {
            $query->where('country_id', $request->integer('country_id'));
        }
        if ($request->filled('region_id')) {
            $query->where('region_id', $request->integer('region_id'));
        }
        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('city', 'like', $term));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        // Server-side scope: non-admins see only their allowed schools.
        $allowed = $request->user()->allowedSchoolIds();
        if ($allowed !== null) {
            $query->whereIn('id', $allowed);
        }

        return $query;
    }

    public function store(StoreSchoolRequest $request): JsonResponse
    {
        $data = $request->safe()->except('image');
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('venues', 'public');
        }

        $school = School::create($data);

        return SchoolResource::make($school->load(['country', 'region']))
            ->response()
            ->setStatusCode(201);
    }

    public function show(School $school): SchoolResource
    {
        $this->authorize('view', $school);

        return SchoolResource::make($school->load(['country', 'region']));
    }

    public function update(UpdateSchoolRequest $request, School $school): SchoolResource
    {
        $this->authorize('update', $school);

        $data = $request->safe()->except('image');
        if ($request->hasFile('image')) {
            if ($school->image_path) {
                Storage::disk('public')->delete($school->image_path);
            }
            $data['image_path'] = $request->file('image')->store('venues', 'public');
        }

        $school->update($data);

        return SchoolResource::make($school->load(['country', 'region']));
    }

    public function destroy(School $school): Response
    {
        $this->authorize('delete', $school);

        $school->delete();

        return response()->noContent();
    }
}
