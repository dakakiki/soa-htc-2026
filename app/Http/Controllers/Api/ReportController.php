<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Support\ReportSummary;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Setting;
use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PdfWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Competition reporting (Faza 5 Slice 5f, CC-12 / ADR-0023). Read-only aggregate
 * over live data, gated by `reports.view`. Filters and an optional group_by
 * dimension are validated here; the aggregation itself lives in ReportSummary.
 */
class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('reports.view');

        $validated = $request->validate([
            'season_id' => ['nullable', 'integer'],
            'country_id' => ['nullable', 'integer'],
            'region_id' => ['nullable', 'integer'],
            'school_id' => ['nullable', 'integer'],
            'coordinator_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'difficulty_level_id' => ['nullable', 'integer'],
            'quiz_id' => ['nullable', 'integer'],
            'exam_id' => ['nullable', 'integer'],
            'test_id' => ['nullable', 'integer'],
            'group_by' => ['nullable', Rule::in(['country', 'region', 'school', 'level', 'quiz', 'exam', 'test'])],
        ]);

        // Default the population to the active season unless one is named.
        $echoedFilters = $validated;
        $echoedFilters['season_id'] = $validated['season_id'] ?? SeasonContext::active()?->id;

        $filters = $echoedFilters;
        $filters['coordinator_school_ids'] = $this->coordinatorSchoolIds(
            isset($validated['coordinator_user_id']) ? (int) $validated['coordinator_user_id'] : null
        );

        return response()->json([
            'filters' => $echoedFilters,
            ...ReportSummary::build($filters),
        ]);
    }

    /**
     * A two-dimension cross-tab of average score (heatmap) — e.g. country × level.
     * Same filters as the summary; the two dimensions are validated against the
     * same set as group_by. Read-only, gated by reports.view.
     */
    public function matrix(Request $request): JsonResponse
    {
        $this->authorize('reports.view');

        $dims = ['country', 'region', 'school', 'level', 'quiz', 'exam', 'test'];

        $validated = $request->validate([
            'row_by' => ['required', Rule::in($dims)],
            'col_by' => ['required', Rule::in($dims)],
            'season_id' => ['nullable', 'integer'],
            'country_id' => ['nullable', 'integer'],
            'region_id' => ['nullable', 'integer'],
            'school_id' => ['nullable', 'integer'],
            'coordinator_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'difficulty_level_id' => ['nullable', 'integer'],
            'quiz_id' => ['nullable', 'integer'],
            'exam_id' => ['nullable', 'integer'],
            'test_id' => ['nullable', 'integer'],
        ]);

        $filters = $validated;
        $filters['season_id'] = $validated['season_id'] ?? SeasonContext::active()?->id;
        $filters['coordinator_school_ids'] = $this->coordinatorSchoolIds(
            isset($validated['coordinator_user_id']) ? (int) $validated['coordinator_user_id'] : null
        );

        return response()->json(ReportSummary::matrix($filters, $validated['row_by'], $validated['col_by']));
    }

    /**
     * The current report (with its filters) as a branded PDF — same data as the
     * summary, rendered through the reusable {@see PdfWriter} (SOA HTC header +
     * logo). Landscape so the breakdown table breathes.
     */
    public function exportPdf(Request $request): Response
    {
        $this->authorize('reports.view');

        $dims = ['country', 'region', 'school', 'level', 'quiz', 'exam', 'test'];

        $validated = $request->validate([
            'season_id' => ['nullable', 'integer'],
            'country_id' => ['nullable', 'integer'],
            'region_id' => ['nullable', 'integer'],
            'school_id' => ['nullable', 'integer'],
            'coordinator_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'difficulty_level_id' => ['nullable', 'integer'],
            'quiz_id' => ['nullable', 'integer'],
            'exam_id' => ['nullable', 'integer'],
            'test_id' => ['nullable', 'integer'],
            'group_by' => ['nullable', Rule::in($dims)],
            // The on-screen heatmap + compare selections, so the PDF mirrors the page.
            'heat_row_by' => ['nullable', Rule::in($dims)],
            'heat_col_by' => ['nullable', Rule::in($dims)],
            'compare_by' => ['nullable', Rule::in($dims)],
            'compare_ids' => ['nullable', 'array'],
            'compare_ids.*' => ['integer'],
        ]);

        $echoed = $validated;
        $echoed['season_id'] = $validated['season_id'] ?? SeasonContext::active()?->id;

        $filters = $echoed;
        $filters['coordinator_school_ids'] = $this->coordinatorSchoolIds(
            isset($validated['coordinator_user_id']) ? (int) $validated['coordinator_user_id'] : null
        );

        $data = ReportSummary::build($filters);

        $matrix = (! empty($validated['heat_row_by']) && ! empty($validated['heat_col_by']))
            ? ReportSummary::matrix($filters, $validated['heat_row_by'], $validated['heat_col_by'])
            : null;

        // Compare: the chosen dimension, narrowed to the picked members.
        $compareBy = $validated['compare_by'] ?? null;
        $compareRows = [];
        if ($compareBy !== null) {
            $ids = array_map('intval', $validated['compare_ids'] ?? []);
            $compareRows = collect(ReportSummary::build($filters + ['group_by' => $compareBy])['rows'])
                ->when($ids !== [], fn ($rows) => $rows->filter(fn ($r) => in_array($r['key'], $ids, true)))
                ->values()->all();
        }

        $html = $this->reportHtml($data, $echoed, $matrix, $compareRows, $compareBy);
        $pdf = PdfWriter::toString($html, 'Competition report', 'L');

        $filename = 'report-'.now()->format('Y-m-d_His').'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * The report body as mPDF-friendly HTML (tables + inline styles; no flex or
     * color-mix). Totals, rates, funnel and — when grouped — the breakdown table.
     *
     * @param  array<string, mixed>  $data  ReportSummary::build() output
     * @param  array<string, mixed>  $f  echoed filters (for the scope line)
     * @param  array<string, mixed>|null  $matrix  ReportSummary::matrix() output (heatmap)
     * @param  list<array<string, mixed>>  $compareRows  the picked members to compare
     */
    private function reportHtml(array $data, array $f, ?array $matrix = null, array $compareRows = [], ?string $compareBy = null): string
    {
        $setting = Setting::current();
        $brand = $setting->color_primary ?: '#2563eb';
        $onBrand = $setting->color_on_primary ?: '#ffffff';

        $t = $data['totals'];
        $s = $t['score'];
        $stamp = now()->format('Y-m-d H:i');
        $scope = $this->filterSummary($f);

        $pct = fn (int $n, int $d): string => $d > 0 ? round($n / $d * 100).'%' : '—';
        $participation = $pct((int) $t['started'], (int) $t['registered']);
        $completion = $pct((int) $t['submitted'], (int) $t['started']);
        $publish = $pct((int) $t['published'], (int) $t['submitted']);

        $totCells = '';
        foreach ([['Registered', $t['registered'], '#111827'], ['Started', $t['started'], '#111827'], ['Submitted', $t['submitted'], '#111827'], ['Published', $t['published'], '#059669'], ['Void', $t['void'], '#d97706']] as [$label, $val, $color]) {
            $totCells .= '<td width="20%" style="border:0.6pt solid #e5e7eb;padding:6px;">'
                .'<div style="font-size:7pt;color:#6b7280;">'.$label.'</div>'
                .'<div style="font-size:15pt;font-weight:bold;color:'.$color.';">'.$val.'</div></td>';
        }

        $base = max(1, (int) $t['registered']);
        $funnel = '';
        foreach ([['Registered', $t['registered']], ['Started', $t['started']], ['Submitted', $t['submitted']], ['Published', $t['published']]] as [$label, $val]) {
            $w = (int) round((int) $val / $base * 100);
            $funnel .= '<tr>'
                .'<td width="16%" style="font-size:8pt;color:#6b7280;padding:2px 0;">'.$label.'</td>'
                .'<td width="68%"><table width="100%" cellspacing="0" cellpadding="0"><tr>'
                    .($w > 0 ? '<td width="'.$w.'%" style="background:'.$brand.';font-size:3pt;line-height:10px;">&nbsp;</td>' : '')
                    .($w < 100 ? '<td style="background:#eef2f7;font-size:3pt;line-height:10px;">&nbsp;</td>' : '')
                .'</tr></table></td>'
                .'<td width="16%" style="text-align:right;font-size:8pt;padding:2px 0;">'.$val.' ('.$w.'%)</td>'
                .'</tr>';
        }

        $breakdown = '';
        if (! empty($data['group_by'])) {
            $dim = ucfirst((string) $data['group_by']);
            $head = '<tr style="background:'.$brand.';color:'.$onBrand.';">'
                .'<th style="text-align:left;padding:5px;">'.$dim.'</th>'
                .'<th style="text-align:right;padding:5px;">Reg.</th><th style="text-align:right;padding:5px;">Started</th>'
                .'<th style="text-align:right;padding:5px;">Submitted</th><th style="text-align:right;padding:5px;">Published</th>'
                .'<th style="text-align:right;padding:5px;">Void</th><th style="text-align:right;padding:5px;">Avg</th><th style="text-align:right;padding:5px;">Median</th></tr>';
            $rows = '';
            foreach ($data['rows'] as $i => $r) {
                $bg = $i % 2 === 1 ? 'background:#f9fafb;' : '';
                $rows .= '<tr style="'.$bg.'">'
                    .'<td style="padding:4px 5px;">'.e($r['label'] ?? '—').'</td>'
                    .'<td style="text-align:right;padding:4px 5px;">'.($r['registered'] ?? '—').'</td>'
                    .'<td style="text-align:right;padding:4px 5px;">'.$r['started'].'</td>'
                    .'<td style="text-align:right;padding:4px 5px;">'.$r['submitted'].'</td>'
                    .'<td style="text-align:right;padding:4px 5px;color:#059669;">'.$r['published'].'</td>'
                    .'<td style="text-align:right;padding:4px 5px;color:#d97706;">'.$r['void'].'</td>'
                    .'<td style="text-align:right;padding:4px 5px;">'.($r['score']['avg'] ?? '—').'</td>'
                    .'<td style="text-align:right;padding:4px 5px;">'.($r['score']['median'] ?? '—').'</td>'
                    .'</tr>';
            }
            $breakdown = '<h3 style="font-size:10pt;margin:12px 0 4px;page-break-after:avoid;">Breakdown — '.$dim.'</h3>'
                .'<table width="100%" cellspacing="0" cellpadding="0" style="border:0.6pt solid #e5e7eb;font-size:8pt;"><thead>'.$head.'</thead><tbody>'.$rows.'</tbody></table>';
        }

        $scoreLine = 'Avg: <b>'.($s['avg'] ?? '—').'</b> &nbsp; Min: <b>'.($s['min'] ?? '—').'</b> &nbsp; Max: <b>'.($s['max'] ?? '—').'</b> &nbsp; Median: <b>'.($s['median'] ?? '—').'</b> &nbsp; Scored: '.$s['count'];

        $heatmap = $matrix !== null ? $this->heatmapHtml($matrix, $brand) : '';
        $compare = $this->compareHtml($compareRows, $compareBy, $brand, $onBrand);

        return <<<HTML
            <div style="font-size:9pt;color:#111827;">
                <h1 style="font-size:15pt;margin:0;">Competition report</h1>
                <div style="font-size:8pt;color:#6b7280;margin:2px 0 10px;">Generated {$stamp} &nbsp;&middot;&nbsp; {$scope}</div>

                <h3 style="font-size:10pt;margin:6px 0 4px;page-break-after:avoid;">Totals</h3>
                <table width="100%" cellspacing="0" cellpadding="0"><tr>{$totCells}</tr></table>
                <div style="font-size:8pt;color:#374151;margin:6px 0 10px;padding:5px;border:0.6pt solid #e5e7eb;">{$scoreLine}</div>

                <h3 style="font-size:10pt;margin:6px 0 4px;page-break-after:avoid;">Rates</h3>
                <table width="100%" cellspacing="0" cellpadding="0"><tr>
                    <td width="33%" style="border:0.6pt solid #e5e7eb;padding:6px;"><div style="font-size:7pt;color:#6b7280;">PARTICIPATION</div><div style="font-size:13pt;font-weight:bold;">{$participation}</div><div style="font-size:7pt;color:#9ca3af;">Started / Registered</div></td>
                    <td width="33%" style="border:0.6pt solid #e5e7eb;padding:6px;"><div style="font-size:7pt;color:#6b7280;">COMPLETION</div><div style="font-size:13pt;font-weight:bold;">{$completion}</div><div style="font-size:7pt;color:#9ca3af;">Submitted / Started</div></td>
                    <td width="34%" style="border:0.6pt solid #e5e7eb;padding:6px;"><div style="font-size:7pt;color:#6b7280;">PUBLISH RATE</div><div style="font-size:13pt;font-weight:bold;">{$publish}</div><div style="font-size:7pt;color:#9ca3af;">Published / Submitted</div></td>
                </tr></table>

                <h3 style="font-size:10pt;margin:12px 0 4px;page-break-after:avoid;">Participation funnel</h3>
                <table width="100%" cellspacing="0" cellpadding="0">{$funnel}</table>

                {$breakdown}

                {$heatmap}

                {$compare}
            </div>
            HTML;
    }

    /**
     * Heatmap (average score cross-tab) as solid-tinted cells — mPDF can't do the
     * screen's color-mix gradient, so the tint is computed to a flat hex here. Caps
     * to the busiest rows/columns like the UI.
     *
     * @param  array<string, mixed>  $matrix  ReportSummary::matrix() output
     */
    private function heatmapHtml(array $matrix, string $brand): string
    {
        if (empty($matrix['cells'])) {
            return '';
        }

        $cells = [];
        $rowTot = [];
        $colTot = [];
        foreach ($matrix['cells'] as $c) {
            $cells[$c['row_key'].':'.$c['col_key']] = $c;
            $rowTot[$c['row_key']] = ($rowTot[$c['row_key']] ?? 0) + $c['count'];
            $colTot[$c['col_key']] = ($colTot[$c['col_key']] ?? 0) + $c['count'];
        }

        $byBusiest = fn (array $axis, array $tot, int $n) => array_slice(
            collect($axis)->sortByDesc(fn ($a) => $tot[$a['key']] ?? 0)->values()->all(), 0, $n
        );
        $rows = $byBusiest($matrix['rows'], $rowTot, 12);
        $cols = $byBusiest($matrix['cols'], $colTot, 8);

        $min = (float) $matrix['min'];
        $max = (float) $matrix['max'];

        $head = '<tr><th style="padding:4px;"></th>';
        foreach ($cols as $col) {
            $head .= '<th style="padding:4px;text-align:center;font-size:7pt;color:#6b7280;">'.e($col['label'] ?? '—').'</th>';
        }
        $head .= '</tr>';

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr><th style="padding:4px;text-align:left;font-size:8pt;color:#374151;">'.e($row['label'] ?? '—').'</th>';
            foreach ($cols as $col) {
                $cell = $cells[$row['key'].':'.$col['key']] ?? null;
                if ($cell === null) {
                    $body .= '<td style="background:#f9fafb;color:#d1d5db;text-align:center;padding:5px;">—</td>';

                    continue;
                }
                $ratio = $max > $min ? ($cell['avg'] - $min) / ($max - $min) : 0.5;
                $mix = 0.15 + $ratio * 0.70;
                $bg = self::tint($brand, $mix);
                $fg = $mix >= 0.55 ? '#ffffff' : '#1f2937';
                $body .= '<td style="background:'.$bg.';color:'.$fg.';text-align:center;padding:5px;font-weight:bold;">'.$cell['avg'].'</td>';
            }
            $body .= '</tr>';
        }

        return '<h3 style="font-size:10pt;margin:12px 0 4px;page-break-after:avoid;">Heatmap — average score</h3>'
            .'<table cellspacing="2" cellpadding="0" style="font-size:8pt;"><thead>'.$head.'</thead><tbody>'.$body.'</tbody></table>'
            .'<div style="font-size:7pt;color:#9ca3af;margin-top:2px;">Darker = higher average score.</div>';
    }

    /**
     * Compare table: the picked members as rows, measures as columns, with the
     * leader in each measure highlighted — the flipped on-screen layout.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function compareHtml(array $rows, ?string $compareBy, string $brand, string $onBrand): string
    {
        if ($compareBy === null || $rows === []) {
            return '';
        }

        $measures = [
            ['Registered', fn ($r) => $r['registered']],
            ['Started', fn ($r) => $r['started']],
            ['Submitted', fn ($r) => $r['submitted']],
            ['Published', fn ($r) => $r['published']],
            ['Void', fn ($r) => $r['void']],
            ['Avg', fn ($r) => $r['score']['avg']],
            ['Median', fn ($r) => $r['score']['median']],
        ];

        // Max per measure (for the leader highlight), only when comparing 2+.
        $maxes = [];
        foreach ($measures as [$label, $get]) {
            $vals = array_filter(array_map($get, $rows), fn ($v) => $v !== null);
            $maxes[$label] = count($rows) > 1 && $vals !== [] ? max($vals) : null;
        }

        $head = '<tr style="background:'.$brand.';color:'.$onBrand.';"><th style="text-align:left;padding:5px;">'.ucfirst($compareBy).'</th>';
        foreach ($measures as [$label]) {
            $head .= '<th style="text-align:center;padding:5px;">'.$label.'</th>';
        }
        $head .= '</tr>';

        $body = '';
        foreach ($rows as $i => $r) {
            $body .= '<tr style="'.($i % 2 === 1 ? 'background:#f9fafb;' : '').'">'
                .'<td style="padding:4px 5px;">'.e($r['label'] ?? '—').'</td>';
            foreach ($measures as [$label, $get]) {
                $v = $get($r);
                $lead = $maxes[$label] !== null && $v !== null && $v === $maxes[$label];
                $style = 'text-align:center;padding:4px 5px;'.($lead ? 'background:'.self::tint($brand, 0.18).';font-weight:bold;' : '');
                $body .= '<td style="'.$style.'">'.($v ?? '—').'</td>';
            }
            $body .= '</tr>';
        }

        return '<h3 style="font-size:10pt;margin:12px 0 4px;page-break-after:avoid;">Compare — '.ucfirst($compareBy).'</h3>'
            .'<table width="100%" cellspacing="0" cellpadding="0" style="border:0.6pt solid #e5e7eb;font-size:8pt;"><thead>'.$head.'</thead><tbody>'.$body.'</tbody></table>';
    }

    /** Mix a brand hex with white by ratio (1 = full brand, 0 = white). */
    private static function tint(string $hex, float $ratio): string
    {
        [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x') ?: [37, 99, 235];
        $mix = fn (int $c) => (int) round($c * $ratio + 255 * (1 - $ratio));

        return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
    }

    /**
     * A compact human line of the applied filters for the PDF scope caption.
     *
     * @param  array<string, mixed>  $f
     */
    private function filterSummary(array $f): string
    {
        $map = [
            'country_id' => ['Country', fn ($id) => Country::find($id)?->name],
            'region_id' => ['Region', fn ($id) => Region::find($id)?->name],
            'school_id' => ['Venue', fn ($id) => School::find($id)?->name],
            'difficulty_level_id' => ['Level', fn ($id) => DifficultyLevel::find($id)?->level_short],
            'coordinator_user_id' => ['Coordinator', fn ($id) => User::find($id)?->name],
            'quiz_id' => ['Quiz', fn ($id) => Quiz::find($id)?->title],
            'exam_id' => ['Exam', fn ($id) => Exam::find($id)?->title],
            'test_id' => ['Test', fn ($id) => Test::find($id)?->title],
        ];

        $parts = [];
        foreach ($map as $key => [$label, $resolve]) {
            if (! empty($f[$key]) && ($name = $resolve($f[$key])) !== null) {
                $parts[] = $label.': '.e($name);
            }
        }

        return $parts === [] ? 'All competitors (no filters)' : implode(' &middot; ', $parts);
    }

    /**
     * Bounded option lists that populate the report's filter controls. Cascades:
     * regions + schools are returned only for a chosen country; exams for a chosen
     * quiz; and tests for the chosen quiz — narrowed to a single exam's tests when
     * an exam is also chosen (quiz → exam → test). Empty otherwise, so the client
     * keeps those selects disabled until the parent is picked. Everything else is
     * small enough to send in full.
     */
    public function filters(Request $request): JsonResponse
    {
        $this->authorize('reports.view');

        $countryId = $request->integer('country_id') ?: null;
        $quizId = $request->integer('quiz_id') ?: null;
        $examId = $request->integer('exam_id') ?: null;

        // Exams and tests belong to the chosen quiz (quiz → exams → tests).
        $quiz = $quizId
            ? Quiz::query()->with([
                'exams' => fn ($q) => $q->where('exams.status', 'active'),
                'exams.tests' => fn ($q) => $q->where('tests.status', 'active'),
            ])->find($quizId)
            : null;

        $exams = $quiz
            ? $quiz->exams->map(fn (Exam $e) => ['id' => $e->id, 'title' => $e->title])->sortBy('title')->values()
            : [];

        // With an exam chosen, tests cascade to just that exam's tests; otherwise
        // the union of every test in the quiz.
        $testSource = $quiz && $examId
            ? ($quiz->exams->firstWhere('id', $examId)?->tests ?? collect())
            : ($quiz?->exams->flatMap(fn (Exam $e) => $e->tests) ?? collect());

        $tests = $quiz
            ? $testSource
                ->unique('id')
                ->map(fn (Test $t) => ['id' => $t->id, 'title' => $t->title])
                ->sortBy('title')
                ->values()
            : [];

        $coordinatorRoleIds = Role::query()
            ->whereIn('key', [SystemRole::CountryCoordinator->value, SystemRole::SchoolCoordinator->value])
            ->pluck('id');

        $seasonId = SeasonContext::active()?->id;

        $coordinators = User::query()
            ->when($seasonId, fn ($q) => $q->whereHas('seasonAssignments', fn ($a) => $a
                ->where('season_id', $seasonId)
                ->where('status', 'active')
                ->whereIn('role_id', $coordinatorRoleIds)))
            ->when(! $seasonId, fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'countries' => Country::query()->orderBy('name')->get(['id', 'name']),
            'regions' => $countryId
                ? Region::query()->where('country_id', $countryId)->orderBy('name')->get(['id', 'name'])
                : [],
            'schools' => $countryId
                ? School::query()->where('country_id', $countryId)->orderBy('name')->get(['id', 'name'])
                : [],
            'levels' => DifficultyLevel::query()->orderBy('position')->get(['id', 'level_short'])
                ->map(fn (DifficultyLevel $l) => ['id' => $l->id, 'label' => $l->level_short]),
            'quizzes' => Quiz::query()->where('status', 'active')->orderBy('title')->get(['id', 'title']),
            'exams' => $exams,
            'tests' => $tests,
            'coordinators' => $coordinators,
        ]);
    }

    /**
     * The schools a chosen coordinator may see, used to narrow the population.
     * Null = no coordinator filter (or a global-scope user, which narrows nothing);
     * an array (possibly empty) = restrict to exactly those schools.
     *
     * @return list<int>|null
     */
    private function coordinatorSchoolIds(?int $coordinatorUserId): ?array
    {
        if ($coordinatorUserId === null) {
            return null;
        }

        $coordinator = User::find($coordinatorUserId);
        $schoolIds = $coordinator?->allowedSchoolIds();

        // A global-scope coordinator (null) narrows nothing.
        return $schoolIds?->values()->all();
    }
}
