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

export interface PublishQuiz {
    id: number;
    title: string;
    exams: PublishExam[];
}

export function overview() {
    return http.get<{ quizzes: PublishQuiz[] }>('/api/results/overview');
}

export function publish(payload: { scope: 'test' | 'exam'; id: number; unpublish?: boolean }) {
    return http.post<{ action: string; attempts_count: number }>('/api/results/publish', payload);
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
