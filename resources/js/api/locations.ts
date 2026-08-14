import { http } from '@/api/http';
import type { Country, Region } from '@/types/models';

/*
 * Mutations for the Countries & Regions admin screen. Listing reuses the shared
 * reference endpoints (listCountries / listRegions in api/reference.ts).
 */

export interface CountryPayload {
    code: string;
    name: string;
}

export interface RegionPayload {
    country_id: number;
    name: string;
}

export function createCountry(payload: CountryPayload) {
    return http.post<{ data: Country }>('/api/countries', payload);
}

export function updateCountry(id: number, payload: CountryPayload) {
    return http.put<{ data: Country }>(`/api/countries/${id}`, payload);
}

export function deleteCountry(id: number) {
    return http.delete(`/api/countries/${id}`);
}

export function createRegion(payload: RegionPayload) {
    return http.post<{ data: Region }>('/api/regions', payload);
}

export function updateRegion(id: number, payload: { name: string }) {
    return http.put<{ data: Region }>(`/api/regions/${id}`, payload);
}

export function deleteRegion(id: number) {
    return http.delete(`/api/regions/${id}`);
}
