import { http } from '@/api/http';
import type { Paginated, Quiz } from '@/types/models';

export interface QuizPayload {
    title: string;
    description?: string | null;
    quiz_type: string;
    // Only sent when set/changed; omit to keep the current code.
    quiz_password?: string | null;
    clear_password?: boolean;
    status?: string;
    level_ids: number[];
    // Ordered — the array order becomes the exam order in the quiz.
    exam_ids: number[];
}

export interface QuizListParams {
    page?: number;
    search?: string;
    quiz_type?: string;
    level_id?: number;
    status?: string;
    per_page?: number;
}

export function listQuizzes(params: QuizListParams = {}) {
    return http.get<Paginated<Quiz>>('/api/quizzes', { params });
}

export function getQuiz(id: number) {
    return http.get<{ data: Quiz }>(`/api/quizzes/${id}`);
}

export function createQuiz(payload: QuizPayload) {
    return http.post<{ data: Quiz }>('/api/quizzes', payload);
}

export function updateQuiz(id: number, payload: Partial<QuizPayload>) {
    return http.put<{ data: Quiz }>(`/api/quizzes/${id}`, payload);
}

export function setQuizStatus(id: number, status: string) {
    return http.put<{ data: Quiz }>(`/api/quizzes/${id}`, { status });
}

export function deleteQuiz(id: number) {
    return http.delete(`/api/quizzes/${id}`);
}
