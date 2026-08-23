import { http } from '@/api/http';
import type { Paginated, School } from '@/types/models';

export interface SchoolPayload {
    country_id: number;
    region_id?: number | null;
    name: string;
    status?: string;
    city?: string | null;
    address?: string | null;
    phone?: string | null;
    email?: string | null;
    hours_eng_per_week?: number | null;
    invigilators_count?: number | null;
    school_type?: string | null;
}

export interface SchoolListParams {
    page?: number;
    country_id?: number;
    region_id?: number;
    per_page?: number;
    search?: string;
    status?: string;
}

export function listSchools(params: SchoolListParams = {}) {
    return http.get<Paginated<School>>('/api/schools', { params });
}

export function getSchool(id: number) {
    return http.get<{ data: School }>(`/api/schools/${id}`);
}

function toFormData(payload: SchoolPayload, image?: File | null): FormData {
    const fd = new FormData();
    Object.entries(payload).forEach(([key, value]) => {
        if (value !== null && value !== undefined) {
            fd.append(key, String(value));
        }
    });
    if (image) {
        fd.append('image', image);
    }
    return fd;
}

export function createSchool(payload: SchoolPayload, image?: File | null) {
    return http.post<{ data: School }>('/api/schools', toFormData(payload, image));
}

export function updateSchool(id: number, payload: SchoolPayload, image?: File | null) {
    // Method spoofing: multipart bodies aren't parsed on PUT, so POST with _method.
    const fd = toFormData(payload, image);
    fd.append('_method', 'PUT');
    return http.post<{ data: School }>(`/api/schools/${id}`, fd);
}

export function setSchoolStatus(id: number, status: string) {
    return http.put<{ data: School }>(`/api/schools/${id}`, { status });
}

export function deleteSchool(id: number) {
    return http.delete(`/api/schools/${id}`);
}

/** Export the currently filtered venues as an .xlsx (legacy layout). */
export function exportSchools(params: SchoolListParams = {}) {
    return http.get('/api/schools/export', { params, responseType: 'blob' });
}

/** Download the venues import .xlsx template. */
export function schoolImportTemplate() {
    return http.get('/api/schools/import/template', { responseType: 'blob' });
}

export interface SchoolImportSummary {
    created: number;
    updated: number;
    error_count: number;
}

/** Bulk create/update venues from a filled-in .xlsx (an exported file works too). */
export function importSchools(file: File) {
    const fd = new FormData();
    fd.append('file', file);
    return http.post<SchoolImportSummary>('/api/schools/import', fd);
}

/** The same file back with an "Error" column marking each invalid row. */
export function importSchoolErrors(file: File) {
    const fd = new FormData();
    fd.append('file', file);
    return http.post('/api/schools/import/errors', fd, { responseType: 'blob' });
}
