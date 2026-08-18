import { http } from '@/api/http';
import type { Paginated, Registration } from '@/types/models';

export interface RegistrationPayload {
    school_id: number;
    school_external?: string | null;
    difficulty_level_id: number;
    name: string;
    date_of_birth?: string | null;
    grade: number;
    status?: string;
    attendance?: string;
}

export interface RegistrationListParams {
    page?: number;
    search?: string;
    country_id?: number;
    region_id?: number;
    school_id?: number;
    level_id?: number;
    grade?: number;
    exam_round_id?: number;
    status?: string;
    attendance?: string;
    per_page?: number;
}

export function listRegistrations(params: RegistrationListParams = {}) {
    return http.get<Paginated<Registration>>('/api/registrations', { params });
}

export function getRegistration(id: number) {
    return http.get<{ data: Registration }>(`/api/registrations/${id}`);
}

export function createRegistration(payload: RegistrationPayload) {
    return http.post<{ data: Registration }>('/api/registrations', payload);
}

export function updateRegistration(id: number, payload: Partial<RegistrationPayload>) {
    return http.put<{ data: Registration }>(`/api/registrations/${id}`, payload);
}

export function setRegistrationStatus(id: number, status: string) {
    return http.put<{ data: Registration }>(`/api/registrations/${id}`, { status });
}

export function deleteRegistration(id: number) {
    return http.delete(`/api/registrations/${id}`);
}
