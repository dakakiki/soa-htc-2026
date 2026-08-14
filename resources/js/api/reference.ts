import { http } from '@/api/http';
import type { Country, LevelOption, Permission, Region, Role } from '@/types/models';

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

/** Ordered difficulty level short codes used as competitor-count columns. */
export function listLevelColumns() {
    return http.get<{ data: string[] }>('/api/difficulty-level-columns');
}

/** Difficulty levels as pickable options for content forms. */
export function listLevelOptions() {
    return http.get<{ data: LevelOption[] }>('/api/difficulty-level-options');
}
