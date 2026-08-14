import { http } from '@/api/http';
import type { Coordinator, Paginated } from '@/types/models';

export interface CoordinatorPayload {
    name: string;
    email: string;
    password?: string;
    country_id: number;
    region_id?: number | null;
    role_id: number;
    school_ids: number[];
    status?: string;
    city?: string | null;
    address?: string | null;
    phone?: string | null;
    can_student_insert?: boolean;
    can_student_edit?: boolean;
    can_student_delete?: boolean;
    can_reset_test_results?: boolean;
}

export interface CoordinatorListParams {
    page?: number;
    search?: string;
    country_id?: number;
    region_id?: number;
    role_id?: number;
    school_id?: number;
    status?: string;
}

export interface CoordinatorFiles {
    image?: File | null;
    fileUpload?: File | null;
}

export function listCoordinators(params: CoordinatorListParams = {}) {
    return http.get<Paginated<Coordinator>>('/api/coordinators', { params });
}

export function getCoordinator(id: number) {
    return http.get<{ data: Coordinator }>(`/api/coordinators/${id}`);
}

function toFormData(payload: Partial<CoordinatorPayload>, files?: CoordinatorFiles): FormData {
    const fd = new FormData();
    Object.entries(payload).forEach(([key, value]) => {
        if (value === null || value === undefined) {
            return;
        }
        if (Array.isArray(value)) {
            value.forEach((v) => fd.append(`${key}[]`, String(v)));
        } else if (typeof value === 'boolean') {
            fd.append(key, value ? '1' : '0');
        } else {
            fd.append(key, String(value));
        }
    });
    if (files?.image) {
        fd.append('image', files.image);
    }
    if (files?.fileUpload) {
        fd.append('file_upload', files.fileUpload);
    }
    return fd;
}

export function createCoordinator(payload: CoordinatorPayload, files?: CoordinatorFiles) {
    return http.post<{ data: Coordinator }>('/api/coordinators', toFormData(payload, files));
}

export function updateCoordinator(id: number, payload: Partial<CoordinatorPayload>, files?: CoordinatorFiles) {
    // Method spoofing: multipart bodies aren't parsed on PUT, so POST with _method.
    const fd = toFormData(payload, files);
    fd.append('_method', 'PUT');
    return http.post<{ data: Coordinator }>(`/api/coordinators/${id}`, fd);
}

export function setCoordinatorStatus(id: number, status: string) {
    return http.put<{ data: Coordinator }>(`/api/coordinators/${id}`, { status });
}

export function deleteCoordinator(id: number) {
    return http.delete(`/api/coordinators/${id}`);
}
