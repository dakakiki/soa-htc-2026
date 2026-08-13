import { http } from '@/api/http';
import type { Country, Permission, Region, Role } from '@/types/models';

export function listCountries() {
    return http.get<{ data: Country[] }>('/api/countries');
}

export function listRegions(countryId?: number) {
    return http.get<{ data: Region[] }>('/api/regions', {
        params: countryId ? { country_id: countryId } : {},
    });
}

export function listRoles() {
    return http.get<{ data: Role[] }>('/api/roles');
}

export function listPermissions() {
    return http.get<{ data: Permission[] }>('/api/permissions');
}
