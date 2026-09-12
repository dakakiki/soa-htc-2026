import { http } from '@/api/http';
import type { CountryMapRow, DashboardData } from '@/types/models';

export function getDashboard() {
    return http.get<{ data: DashboardData }>('/api/dashboard');
}

/**
 * The per-country breakdown behind the world map and the countries table.
 *
 * Separate from the payload above because it was the slowest part of it and the
 * furthest down the page: fetched alongside rather than before, so the headline
 * numbers do not wait on it. Returns an empty list for a scoped account, which
 * has no world map.
 */
export function getDashboardCountries() {
    return http.get<{ data: CountryMapRow[] }>('/api/dashboard/countries');
}
