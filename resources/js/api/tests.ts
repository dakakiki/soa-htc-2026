import { http } from '@/api/http';
import type { Paginated, Test, TestPreview } from '@/types/models';

export interface TestPayload {
    title: string;
    description?: string | null;
    test_type_id?: number | null;
    duration?: number | null;
    status?: string;
    level_ids: number[];
    // Ordered — the array order becomes the question order on the test.
    question_ids: number[];
}

export interface TestListParams {
    page?: number;
    search?: string;
    test_type_id?: number;
    category_id?: number;
    level_id?: number;
    status?: string;
    per_page?: number;
}

export function listTests(params: TestListParams = {}) {
    return http.get<Paginated<Test>>('/api/tests', { params });
}

export function getTest(id: number) {
    return http.get<{ data: Test }>(`/api/tests/${id}`);
}

export function getTestPreview(id: number) {
    return http.get<{ data: TestPreview }>(`/api/tests/${id}/preview`);
}

export function createTest(payload: TestPayload) {
    return http.post<{ data: Test }>('/api/tests', payload);
}

export function updateTest(id: number, payload: Partial<TestPayload>) {
    return http.put<{ data: Test }>(`/api/tests/${id}`, payload);
}

export function setTestStatus(id: number, status: string) {
    return http.put<{ data: Test }>(`/api/tests/${id}`, { status });
}

export function deleteTest(id: number) {
    return http.delete(`/api/tests/${id}`);
}
