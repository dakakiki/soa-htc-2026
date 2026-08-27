import { http } from '@/api/http';

export interface PublishTest {
    id: number;
    title: string;
    completed: number;
    published: number;
    pending: number;
}

export interface PublishExam {
    id: number;
    title: string;
    tests: PublishTest[];
}

/** Filter scope for the publish list: quiz required, the rest narrow it. */
export interface PublishScope {
    country_id?: number | null;
    school_id?: number | null;
    quiz_id?: number | null;
    exam_id?: number | null;
    test_id?: number | null;
}

export interface PublishOverview {
    needs_quiz: boolean;
    quiz: { id: number; title: string } | null;
    exams: PublishExam[];
}

/** The chosen quiz's exams/tests with counts, scoped to the filtered population. */
export function overview(scope: PublishScope) {
    return http.get<PublishOverview>('/api/results/overview', { params: cleanParams(scope) });
}

/** Publish/unpublish a test or round, restricted to the filtered country/venue population. */
export function publish(
    payload: { scope: 'test' | 'exam'; id: number; unpublish?: boolean } & PublishScope
) {
    return http.post<{
        action: string;
        attempts_count: number;
        students_count: number;
        venues_count: number;
        /** Finished attempts in scope that could NOT be published: marking is not final yet. */
        waiting_count: number;
    }>('/api/results/publish', cleanParams(payload));
}

// --- Bulk attempt reset (CC-11) ---

export interface ResetCandidate {
    id: number;
    competitor_number: string;
    name: string;
    country: string | null;
    level: string | null;
    school: string | null;
    resettable: number;
}

export interface ResetScope {
    country_id?: number | null;
    region_id?: number | null;
    school_id?: number | null;
    coordinator_user_id?: number | null;
    difficulty_level_id?: number | null;
    quiz_id?: number | null;
    exam_id?: number | null;
    test_id?: number | null;
    search?: string | null;
}

export interface ResetSummaryResponse {
    data: ResetCandidate[];
    total: number;
    total_attempts: number;
    needs_quiz: boolean;
    truncated: boolean;
}

/** A reset/export target: the full filter scope plus either explicit ids or all-matching. */
export type ResetTarget = ResetScope & { registration_ids?: number[]; all_matching?: boolean };

function cleanParams(obj: object): Record<string, unknown> {
    return Object.fromEntries(Object.entries(obj).filter(([, v]) => v !== null && v !== undefined && v !== ''));
}

/** Competitors with resettable attempts in the quiz scope (quiz_id required). */
export function resetCandidates(scope: ResetScope) {
    return http.get<ResetSummaryResponse>('/api/results/reset-candidates', { params: cleanParams(scope) });
}

export function bulkReset(payload: ResetTarget & { reason: string }) {
    return http.post<{ voided: number; students: number }>('/api/results/attempts/bulk-reset', cleanParams(payload));
}

/** Download an .xlsx record of the reset attempts in the given scope. */
export function exportReset(payload: ResetTarget) {
    return http.post('/api/results/reset-export', cleanParams(payload), { responseType: 'blob' });
}

// --- Results import (offline results → Layer B, ADR-0027) ---

/** The import cascade: competition quizzes, a chosen quiz's exams, and its tests. */
export interface ImportOptions {
    quizzes: { id: number; title: string }[];
    exams: { id: number; title: string }[];
    tests: { id: number; title: string }[];
}

/** Per-outcome counts returned after an import run. */
export interface ImportSummary {
    imported: number;
    updated: number;
    skipped_conflict: number;
    not_found: number;
    invalid: number;
    qualifications: number;
    not_found_numbers: string[];
}

export function importOptions(scope: { quiz_id?: number | null; exam_id?: number | null }) {
    return http.get<ImportOptions>('/api/results/import/options', { params: cleanParams(scope) });
}

/** Upload offline results for one test (competition quiz → exam → test). */
export function importResults(payload: { quiz_id: number; exam_id: number; test_id: number; file: File }) {
    const fd = new FormData();
    fd.append('quiz_id', String(payload.quiz_id));
    fd.append('exam_id', String(payload.exam_id));
    fd.append('test_id', String(payload.test_id));
    fd.append('file', payload.file);

    return http.post<ImportSummary>('/api/results/import', fd);
}

/** Download the blank import template (.xlsx). */
export function importTemplate() {
    return http.get('/api/results/import/template', { responseType: 'blob' });
}

// --- Results export (all from Layer B, with-answers from Layer A) ---

export interface ExportScope {
    country_id?: number | null;
    region_id?: number | null;
    school_id?: number | null;
    difficulty_level_id?: number | null;
    coordinator_user_id?: number | null;
    quiz_id?: number | null;
    exam_id?: number | null;
    test_id?: number | null;
}

/** Download the wide results sheet (.xlsx) for the filtered population. */
export function exportResults(scope: ExportScope) {
    return http.get('/api/results/export', { params: cleanParams(scope), responseType: 'blob' });
}

/** Download the per-question answers sheet (.xlsx) for one test (quiz+exam+test required). */
export function exportResultsWithAnswers(scope: ExportScope) {
    return http.get('/api/results/export-answers', { params: cleanParams(scope), responseType: 'blob' });
}
