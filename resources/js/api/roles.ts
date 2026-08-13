import { http } from '@/api/http';
import type { Role } from '@/types/models';

export interface RolePayload {
    name: string;
    key?: string;
    permissions: string[];
}

export function getRole(id: number) {
    return http.get<{ data: Role }>(`/api/roles/${id}`);
}

export function createRole(payload: RolePayload) {
    return http.post<{ data: Role }>('/api/roles', payload);
}

export function updateRole(id: number, payload: Partial<RolePayload>) {
    return http.put<{ data: Role }>(`/api/roles/${id}`, payload);
}

export function deleteRole(id: number) {
    return http.delete(`/api/roles/${id}`);
}
