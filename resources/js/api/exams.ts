import { http } from '@/api/http';
import type { Exam, Paginated } from '@/types/models';

export interface ExamPayload {
    title: string;
    description?: string | null;
    exam_round_id?: number | null;
    status?: string;
    level_ids: number[];
    // Ordered — the array order becomes the test order in the exam.
    test_ids: number[];
}

export interface ExamListParams {
    page?: number;
    search?: string;
    exam_round_id?: number;
    level_id?: number;
    status?: string;
    per_page?: number;
}

export function listExams(params: ExamListParams = {}) {
    return http.get<Paginated<Exam>>('/api/exams', { params });
}

export function getExam(id: number) {
    return http.get<{ data: Exam }>(`/api/exams/${id}`);
}

export function createExam(payload: ExamPayload) {
    return http.post<{ data: Exam }>('/api/exams', payload);
}

export function updateExam(id: number, payload: Partial<ExamPayload>) {
    return http.put<{ data: Exam }>(`/api/exams/${id}`, payload);
}

export function setExamStatus(id: number, status: string) {
    return http.put<{ data: Exam }>(`/api/exams/${id}`, { status });
}

export function deleteExam(id: number) {
    return http.delete(`/api/exams/${id}`);
}
