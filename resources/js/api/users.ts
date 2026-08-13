import { http } from '@/api/http';
import type { AdminUser, Paginated } from '@/types/models';

export interface UserPayload {
    name: string;
    email: string;
    password?: string;
}

export function listUsers(page = 1) {
    return http.get<Paginated<AdminUser>>('/api/users', { params: { page } });
}

export function createUser(payload: UserPayload) {
    return http.post<{ data: AdminUser }>('/api/users', payload);
}

export function updateUser(id: number, payload: Partial<UserPayload>) {
    return http.put<{ data: AdminUser }>(`/api/users/${id}`, payload);
}
