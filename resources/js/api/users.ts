import { http } from '@/api/http';
import type { AdminUser, Paginated } from '@/types/models';

export interface UserPayload {
    name: string;
    email: string;
    password?: string;
    country_id?: number;
    region_id?: number | null;
    role_id?: number | null;
    status?: string;
    city?: string | null;
    address?: string | null;
    phone?: string | null;
    can_student_insert?: boolean;
    can_student_edit?: boolean;
    can_student_delete?: boolean;
    can_reset_test_results?: boolean;
}

export interface UserListParams {
    page?: number;
    per_page?: number;
    search?: string;
    country_id?: number;
    region_id?: number;
    status?: string;
}

export interface UserFiles {
    image?: File | null;
    fileUpload?: File | null;
}

export function listUsers(params: UserListParams = {}) {
    return http.get<Paginated<AdminUser>>('/api/users', { params });
}

export function getUser(id: number) {
    return http.get<{ data: AdminUser }>(`/api/users/${id}`);
}

function toFormData(payload: Partial<UserPayload>, files?: UserFiles): FormData {
    const fd = new FormData();
    Object.entries(payload).forEach(([key, value]) => {
        if (value === null || value === undefined) {
            return;
        }
        // Booleans must reach Laravel's `boolean` rule as "1"/"0".
        fd.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    });
    if (files?.image) {
        fd.append('image', files.image);
    }
    if (files?.fileUpload) {
        fd.append('file_upload', files.fileUpload);
    }
    return fd;
}

export function createUser(payload: UserPayload, files?: UserFiles) {
    return http.post<{ data: AdminUser }>('/api/users', toFormData(payload, files));
}

export function updateUser(id: number, payload: Partial<UserPayload>, files?: UserFiles) {
    // Method spoofing: multipart bodies aren't parsed on PUT, so POST with _method.
    const fd = toFormData(payload, files);
    fd.append('_method', 'PUT');
    return http.post<{ data: AdminUser }>(`/api/users/${id}`, fd);
}

export function setUserStatus(id: number, status: string) {
    return http.put<{ data: AdminUser }>(`/api/users/${id}`, { status });
}

export function deleteUser(id: number) {
    return http.delete(`/api/users/${id}`);
}
