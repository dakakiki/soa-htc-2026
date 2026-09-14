<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Assessment\Support\SampleRound;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
            'mode' => ['nullable', Rule::in(ReportSummary::MODES)],
            // The breakdown table asks for both populations at once and for every
            // member of its dimension; nothing else on the screen does, and the
            // extra queries are its own (ADR-0091).
            'split_modes' => ['nullable', 'boolean'],
            'all_members' => ['nullable', 'boolean'],
        ]);

        // Default the population to the active season unless one is named.
        $echoedFilters = $validated;
        $echoedFilters['season_id'] = $validated['season_id'] ?? SeasonContext::active()?->id;
        // The contest unless asked otherwise (ADR-0084): practice is a different
        // population, publishes itself, and repeats. Echoed back so the screen can
        // say which of the two it is showing.
        $echoedFilters['mode'] = $validated['mode'] ?? ReportSummary::MODE_DEFAULT;

        $filters = $echoedFilters;
        $filters['coordinator_school_ids'] = $this->populationSchoolIds(
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
            'mode' => ['nullable', Rule::in(ReportSummary::MODES)],
        ]);

        $filters = $validated;
        $filters['season_id'] = $validated['season_id'] ?? SeasonContext::active()?->id;
        $filters['mode'] = $validated['mode'] ?? ReportSummary::MODE_DEFAULT;
        $filters['coordinator_school_ids'] = $this->populationSchoolIds(
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
            'mode' => ['nullable', Rule::in(ReportSummary::MODES)],
            // The on-screen heatmap + compare selections, so the PDF mirrors the page.
            'heat_row_by' => ['nullable', Rule::in($dims)],
            'heat_col_by' => ['nullable', Rule::in($dims)],
            'compare_by' => ['nullable', Rule::in($dims)],
            'compare_ids' => ['nullable', 'array'],
            'compare_ids.*' => ['integer'],
        ]);

        $echoed = $validated;
        $echoed['season_id'] = $validated['season_id'] ?? SeasonContext::active()?->id;
        $echoed['mode'] = $validated['mode'] ?? ReportSummary::MODE_DEFAULT;

        $filters = $echoed;
        $filters['coordinator_school_ids'] = $this->populationSchoolIds(
            isset($validated['coordinator_user_id']) ? (int) $validated['coordinator_user_id'] : null
        );

        // The printed breakdown is the one on screen: both populations, every
        // member (ADR-0091). Compare below keeps the plain shape it reads.
        $data = ReportSummary::build($filters + ['split_modes' => true, 'all_members' => true]);

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
        /*
         * 🔴 People over people, as on the screen (ADR-0085). This line kept the
         * old division after the screen stopped using it, so the printed report —
         * the one that reaches a client — went on saying **134%** where the page
         * said 56%: `started` counts attempts and a child sits several tests.
         */
        $participation = $pct((int) $t['participants'], (int) $t['registered']);
        $completion = $pct((int) $t['submitted'], (int) $t['started']);
        $publish = $pct((int) $t['published'], (int) $t['submitted']);

        $totCells = '';
        // The same five tiles the screen shows, in the same order. Void left both
        // (owner, 14.09): an administrator's reset is not a stage of the contest.
        foreach ([['Registered', $t['registered'], '#111827'], ['Took part', $t['participants'], '#111827'], ['Started', $t['started'], '#111827'], ['Submitted', $t['submitted'], '#111827'], ['Published', $t['published'], '#059669']] as [$label, $val, $color]) {
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

            // Every measure twice — the contest and practice beside each other,
            // never added up (ADR-0091). Counts are children, scores are per
            // attempt, and the sub-header says so on the page rather than in a
            // footnote nobody reads.
            $pair = fn (string $label): string => '<th colspan="2" style="text-align:center;padding:5px;">'.$label.'</th>';
            $head = '<tr style="background:'.$brand.';color:'.$onBrand.';">'
                .'<th rowspan="2" style="text-align:left;padding:5px;">'.$dim.'</th>'
                .'<th rowspan="2" style="text-align:right;padding:5px;">Reg.</th>'
                .$pair('Started').$pair('Submitted').$pair('Published').$pair('Avg').$pair('Median')
                .'</tr><tr style="background:'.$brand.';color:'.$onBrand.';font-size:6.5pt;">'
                .str_repeat('<th style="text-align:right;padding:2px 5px;">Contest</th><th style="text-align:right;padding:2px 5px;">Practice</th>', 5)
                .'</tr>';

            $cell = fn ($v, string $style = ''): string => '<td style="text-align:right;padding:4px 5px;'.$style.'">'.($v ?? '—').'</td>';

            $rows = '';
            foreach ($data['rows'] as $i => $r) {
                $bg = $i % 2 === 1 ? 'background:#f9fafb;' : '';
                // 🪤 Not $c/$s — $s is the totals' score block further down, and
                // borrowing the name emptied the report's own score line.
                $contest = $r['modes']['competition'];
                $practice = $r['modes']['sample'];
                $sub = '';
                foreach ($r['sublabels'] ?? [] as $line) {
                    $sub .= '<div style="font-size:6.5pt;color:#6b7280;">'.e((string) $line).'</div>';
                }

                $rows .= '<tr style="'.$bg.'">'
                    .'<td style="padding:4px 5px;">'.e($r['label'] ?? '—').$sub.'</td>'
                    .$cell($r['registered'])
                    .$cell($contest['participants']).$cell($practice['participants'])
                    .$cell($contest['submitted']).$cell($practice['submitted'])
                    .$cell($contest['published'], 'color:#059669;').$cell($practice['published'], 'color:#059669;')
                    .$cell($contest['score']['avg']).$cell($practice['score']['avg'])
                    .$cell($contest['score']['median']).$cell($practice['score']['median'])
                    .'</tr>';
            }
            $breakdown = '<h3 style="font-size:10pt;margin:12px 0 4px;page-break-after:avoid;">Breakdown — '.$dim.'</h3>'
                .'<div style="font-size:7pt;color:#6b7280;margin-bottom:3px;">Started, Submitted and Published count competitors; Avg and Median are per attempt.</div>'
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
                    <td width="33%" style="border:0.6pt solid #e5e7eb;padding:6px;"><div style="font-size:7pt;color:#6b7280;">PARTICIPATION</div><div style="font-size:13pt;font-weight:bold;">{$participation}</div><div style="font-size:7pt;color:#9ca3af;">Competitors who started / Registered</div></td>
                    <td width="33%" style="border:0.6pt solid #e5e7eb;padding:6px;"><div style="font-size:7pt;color:#6b7280;">COMPLETION</div><div style="font-size:13pt;font-weight:bold;">{$completion}</div><div style="font-size:7pt;color:#9ca3af;">Submitted / Started</div></td>
                    <td width="34%" style="border:0.6pt solid #e5e7eb;padding:6px;"><div style="font-size:7pt;color:#6b7280;">PUBLISH RATE</div><div style="font-size:13pt;font-weight:bold;">{$publish}</div><div style="font-size:7pt;color:#9ca3af;">Published / Submitted</div></td>
                </tr></table>

                <h3 style="font-size:10pt;margin:12px 0 4px;page-break-after:avoid;">Participation funnel</h3>
                <table width="100%" cellspacing="0" cellpadding="0">{$funnel}</table>
                <div style="font-size:7pt;color:#6b7280;margin:3px 0 0;">Registered counts competitors; Started, Submitted and Published count attempts &mdash; a competitor sits several tests, so those bars can pass 100%.</div>

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
    /**
     * The quizzes of one test type (ADR-0092).
     *
     * 🔴 A quiz is practice because its exam sits in a practice ROUND, never
     * because `quizzes.quiz_type` says so — the same boundary the counting uses
     * ({@see SampleRound}), and the one an
     * administrator cannot drift by retyping a field. On the current data the
     * two agree: 6 practice quizzes, 8 contest ones, none in both.
     *
     * 🪤 A quiz with exams in both kinds of round belongs to both lists, which is
     * why this asks "has an exam of that kind" rather than excluding the other.
     *
     * @return Collection<int, Quiz>
     */
    private function quizzesOfType(string $mode)
    {
        /*
         * 🪤 Practice is what a practice round says it is; everything else is the
         * contest, an exam with NO round included. That asymmetry is not
         * sloppiness, it is the counting: `applyMode` takes the practice tests and
         * treats every other attempt as contest, so a quiz whose exam has no round
         * is reported under the contest — and a picker that offered it under
         * neither would hide a quiz whose numbers the report is showing.
         */
        $quizzesWithExam = fn (callable $round) => DB::table('exam_quiz')
            ->join('exams', 'exams.id', '=', 'exam_quiz.exam_id')
            ->leftJoin('exam_rounds', 'exam_rounds.id', '=', 'exams.exam_round_id')
            ->where('exams.status', 'active')
            ->where($round)
            ->select('exam_quiz.quiz_id');

        $practice = fn ($q) => $q->where('exam_rounds.is_sample', true);
        $contest = fn ($q) => $q->where(fn ($w) => $w->whereNull('exam_rounds.id')->orWhere('exam_rounds.is_sample', false));

        return Quiz::query()
            ->where('status', 'active')
            ->when($mode !== 'all', fn ($q) => $q->whereIn(
                'id',
                $quizzesWithExam($mode === 'sample' ? $practice : $contest)
            ))
            ->orderBy('title')
            ->get(['id', 'title']);
    }

    public function filters(Request $request): JsonResponse
    {
        $this->authorize('reports.view');

        $countryId = $request->integer('country_id') ?: null;
        // Only the coordinator list reads the venue; the other options already
        // cascade on country alone.
        $schoolId = $request->integer('school_id') ?: null;
        $quizId = $request->integer('quiz_id') ?: null;
        $examId = $request->integer('exam_id') ?: null;
        // The test type heads the content cascade: a contest quiz and a practice
        // quiz are different things to pick from (ADR-0092).
        $mode = in_array($request->string('mode')->toString(), ReportSummary::MODES, true)
            ? $request->string('mode')->toString()
            : ReportSummary::MODE_DEFAULT;

        // Exams and tests belong to the chosen quiz (quiz → exams → tests).
        $quiz = $quizId
            ? Quiz::query()->with([
                'exams' => fn ($q) => $q->where('exams.status', 'active'),
                'exams.round',
                'exams.tests' => fn ($q) => $q->where('tests.status', 'active'),
            ])->find($quizId)
            : null;

        // 🪤 Each exam used to carry whether its round was the one being run, so
        // Publishing could open on it. The rounds do not advance together —
        // countries sit on National and on Regional Qualifiers at once — so no
        // such exam exists to open on (ADR-0077). Publishing asks instead.
        $exams = $quiz
            ? $quiz->exams->map(fn (Exam $e) => [
                'id' => $e->id,
                'title' => $e->title,
            ])->sortBy('title')->values()
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

        /*
         * The reader's own scope, applied to what the screen OFFERS as well as to
         * what it reports (ADR-0067). A picker that lists every country to somebody
         * who may see one is not a leak of results, but it is a leak of the shape of
         * the competition — and every choice outside the scope answers with zeroes,
         * which reads as broken rather than as forbidden.
         */
        $callerSchoolIds = $this->callerSchoolIds();

        $coordinators = User::query()
            // Two separate `active`s, and both have to hold: the season assignment
            // may be live while the account behind it is closed.
            ->where('status', 'active')
            ->when($seasonId, fn ($q) => $q->whereHas('seasonAssignments', fn ($a) => $a
                ->where('season_id', $seasonId)
                ->where('status', 'active')
                ->whereIn('role_id', $coordinatorRoleIds)))
            ->when(! $seasonId, fn ($q) => $q->whereRaw('1 = 0'))
            /*
             * The chosen country is simply the country the coordinator belongs to.
             * Deriving it from the venues on their assignments instead reads well
             * until a coordinator has no venue yet: that path then says nothing at
             * all, and the picker comes back empty for a country that plainly has
             * coordinators in it.
             */
            ->when($countryId, fn ($q) => $q->where('country_id', $countryId))
            // A chosen venue narrows further: of that country's coordinators, the
            // ones the venue is actually assigned to.
            ->when($schoolId && $seasonId, fn ($q) => $q->whereHas('seasonAssignments', fn ($a) => $a
                ->where('season_id', $seasonId)
                ->where('status', 'active')
                ->whereIn('role_id', $coordinatorRoleIds)
                ->whereHas('schools', fn ($sc) => $sc->where('schools.id', $schoolId))))
            // A scoped reader is offered the coordinators of their own venues only.
            ->when($callerSchoolIds !== null, fn ($q) => $q->whereHas(
                'seasonAssignments',
                fn ($a) => $a->whereHas('schools', fn ($sc) => $sc->whereIn('schools.id', $callerSchoolIds ?? [])),
            ))
            ->orderBy('name')
            ->get(['id', 'name']);

        // Countries and regions are derived from the venues in scope, so the three
        // pickers can never disagree with one another.
        $scopedCountryIds = $callerSchoolIds === null
            ? null
            : School::query()->whereIn('id', $callerSchoolIds)->distinct()->pluck('country_id')->all();

        return response()->json([
            'countries' => Country::query()
                ->when($scopedCountryIds !== null, fn ($q) => $q->whereIn('id', $scopedCountryIds ?? []))
                ->orderBy('name')->get(['id', 'name']),
            'regions' => $countryId
                ? Region::query()->where('country_id', $countryId)
                    ->when($callerSchoolIds !== null, fn ($q) => $q->whereIn(
                        'id',
                        School::query()->whereIn('id', $callerSchoolIds ?? [])->distinct()->pluck('region_id')->filter()->all(),
                    ))
                    ->ordered()->get(['id', 'name'])
                : [],
            'schools' => $countryId
                ? School::query()->where('country_id', $countryId)
                    ->when($callerSchoolIds !== null, fn ($q) => $q->whereIn('id', $callerSchoolIds ?? []))
                    ->orderBy('name')->get(['id', 'name'])
                : [],
            // Joined, ordered and named exactly as /api/difficulty-level-options does,
            // so a level falls under the same heading here as in the Students filter.
            'levels' => DifficultyLevel::query()
                ->join('difficulty_categories', 'difficulty_categories.id', '=', 'difficulty_levels.difficulty_category_id')
                ->orderBy('difficulty_categories.type')->orderBy('difficulty_categories.id')->orderBy('difficulty_levels.position')
                ->get(['difficulty_levels.id', 'difficulty_levels.level_short', 'difficulty_categories.name as category_name'])
                ->map(fn (DifficultyLevel $l) => ['id' => $l->id, 'label' => $l->level_short, 'category_name' => $l->category_name]),
            'quizzes' => $this->quizzesOfType($mode),
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

    /**
     * The caller's OWN row scope. Null means global (`schools.view.all`).
     *
     * @return list<int>|null
     */
    private function callerSchoolIds(): ?array
    {
        return auth()->user()?->allowedSchoolIds()?->values()->all();
    }

    /**
     * The population every report is drawn inside (ADR-0067).
     *
     * Two different things end up in the same list and they must not be confused.
     * `coordinator_user_id` is a FILTER — the reader asking to see one coordinator's
     * schools — and it was the only thing here. The caller's own scope is a
     * BOUNDARY: whatever the request asked for, a non-global reader only ever sees
     * their own schools. `ResultsController::applyPopulationFilters` has always
     * done this, and its comment says in as many words that it is what makes
     * delegating `results.manage`/`reports.view` to a coordinator safe. Reports
     * never did it, so the second half of that sentence was not true.
     *
     * 🪤 An empty list is not "no restriction". A reader scoped to schools the
     * filter excludes sees nothing, which is the right answer: `ReportSummary`
     * turns `[]` into `whereIn(…, [])` and returns zeroes rather than everything.
     *
     * @return list<int>|null
     */
    private function populationSchoolIds(?int $coordinatorUserId): ?array
    {
        $filtered = $this->coordinatorSchoolIds($coordinatorUserId);
        $caller = $this->callerSchoolIds();

        if ($caller === null) {
            return $filtered;
        }

        if ($filtered === null) {
            return $caller;
        }

        return array_values(array_intersect($filtered, $caller));
    }
}
