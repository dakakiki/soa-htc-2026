import { http } from '@/api/http';
import type { Paginated, School } from '@/types/models';

export interface SchoolPayload {
    country_id: number;
    region_id?: number | null;
    name: string;
    status?: string;
}

export interface SchoolListParams {
    page?: number;
    country_id?: number;
    region_id?: number;
    per_page?: number;
}

export function listSchools(params: SchoolListParams = {}) {
    return http.get<Paginated<School>>('/api/schools', { params });
}

export function createSchool(payload: SchoolPayload) {
    return http.post<{ data: School }>('/api/schools', payload);
}

export function updateSchool(id: number, payload: Partial<SchoolPayload>) {
    return http.put<{ data: School }>(`/api/schools/${id}`, payload);
}

export function deleteSchool(id: number) {
    return http.delete(`/api/schools/${id}`);
}
