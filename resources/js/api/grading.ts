import { http } from '@/api/http';

export interface GradingListItem {
    id: number;
    competitor_number: string | null;
    name: string | null;
    test: string | null;
    submitted_at: string | null;
    score: number;
    max_score: number;
}

export interface GradingEssay {
    answer_id: number;
    question_title: string;
    question_description: string | null;
    points: number;
    response: string | null;
    awarded_points: number | null;
    grade_note: string | null;
    graded_at: string | null;
    graded_by: string | null;
}

export interface GradingAttempt {
    attempt: {
        id: number;
        competitor_number: string | null;
        name: string | null;
        test: string | null;
        score: number;
        max_score: number;
        grading_status: string;
        submitted_at: string | null;
    };
    essays: GradingEssay[];
}

export function listPending(page = 1) {
    return http.get<{ data: GradingListItem[]; meta: { current_page: number; last_page: number; total: number } }>('/api/grading/attempts', {
        params: { page },
    });
}

export function getAttempt(id: number) {
    return http.get<GradingAttempt>(`/api/grading/attempts/${id}`);
}

export function gradeEssay(attemptId: number, answerId: number, payload: { awarded_points: number; note?: string; reason?: string }) {
    return http.put<{ grading_status: string; score: number }>(`/api/grading/attempts/${attemptId}/answers/${answerId}`, payload);
}
