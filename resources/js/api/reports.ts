import { http } from '@/api/http';

export interface ScoreStats {
    count: number;
    avg: number | null;
    min: number | null;
    max: number | null;
    median: number | null;
}

export interface ReportMeasures {
    registered: number | null;
    started: number;
    submitted: number;
    published: number;
    void: number;
    score: ScoreStats;
}

export interface ReportRow extends ReportMeasures {
    key: number | null;
    label: string | null;
}

export interface ReportSummary {
    group_by: string | null;
    totals: ReportMeasures & { registered: number };
    rows: ReportRow[];
}

export interface ReportFilterOptions {
    countries: { id: number; name: string }[];
    regions: { id: number; name: string }[];
    schools: { id: number; name: string }[];
    levels: { id: number; label: string }[];
    quizzes: { id: number; title: string }[];
    exams: { id: number; title: string }[];
    tests: { id: number; title: string }[];
    coordinators: { id: number; name: string }[];
}

export type GroupBy = 'country' | 'region' | 'school' | 'level' | 'quiz' | 'exam' | 'test';

export interface ReportQuery {
    country_id?: number | null;
    region_id?: number | null;
    school_id?: number | null;
    coordinator_user_id?: number | null;
    difficulty_level_id?: number | null;
    quiz_id?: number | null;
    exam_id?: number | null;
    test_id?: number | null;
    group_by?: GroupBy | null;
}

/**
 * Bounded option lists for the filter controls. Pass a country to fill
 * regions/schools, a quiz to fill exams/tests, and an exam to narrow tests to
 * that exam (quiz → exam → test cascade).
 */
export function reportFilters(scope?: { country_id?: number | null; quiz_id?: number | null; exam_id?: number | null }) {
    const params: Record<string, number> = {};
    if (scope?.country_id) params.country_id = scope.country_id;
    if (scope?.quiz_id) params.quiz_id = scope.quiz_id;
    if (scope?.exam_id) params.exam_id = scope.exam_id;

    return http.get<ReportFilterOptions>('/api/reports/filters', { params });
}

export function reportSummary(query: ReportQuery) {
    const params = Object.fromEntries(
        Object.entries(query).filter(([, v]) => v !== null && v !== undefined && v !== '')
    );

    return http.get<ReportSummary>('/api/reports/summary', { params });
}

// --- Heatmap cross-tab (CC-12+) ---

export interface MatrixAxis {
    key: number;
    label: string | null;
}

export interface MatrixCell {
    row_key: number;
    col_key: number;
    avg: number;
    count: number;
}

export interface ReportMatrix {
    row_by: GroupBy;
    col_by: GroupBy;
    rows: MatrixAxis[];
    cols: MatrixAxis[];
    cells: MatrixCell[];
    min: number | null;
    max: number | null;
}

/** The current report (filters + on-screen heatmap/compare selections) as a PDF blob. */
export function exportReportPdf(params: Record<string, unknown>) {
    const clean = Object.fromEntries(
        Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '')
    );

    return http.get('/api/reports/export-pdf', { params: clean, responseType: 'blob' });
}

/** Two-dimension average-score cross-tab (heatmap). group_by is ignored here. */
export function reportMatrix(query: ReportQuery, rowBy: GroupBy, colBy: GroupBy) {
    const { group_by: _group, ...rest } = query;
    const params: Record<string, unknown> = { row_by: rowBy, col_by: colBy };
    for (const [k, v] of Object.entries(rest)) {
        if (v !== null && v !== undefined) params[k] = v;
    }

    return http.get<ReportMatrix>('/api/reports/matrix', { params });
}
