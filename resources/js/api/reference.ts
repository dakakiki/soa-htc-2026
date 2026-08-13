import { http } from '@/api/http';
import type { Country, Role } from '@/types/models';

export function listCountries() {
    return http.get<{ data: Country[] }>('/api/countries');
}

export function listRoles() {
    return http.get<{ data: Role[] }>('/api/roles');
}
