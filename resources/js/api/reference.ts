import { http } from '@/api/http';
import type { Country } from '@/types/models';

export function listCountries() {
    return http.get<{ data: Country[] }>('/api/countries');
}
