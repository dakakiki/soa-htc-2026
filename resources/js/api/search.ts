import { http } from '@/api/http';
import type { SearchResults } from '@/types/models';

export function globalSearch(q: string, signal?: AbortSignal) {
    return http.get<{ data: SearchResults }>('/api/search', { params: { q }, signal });
}
