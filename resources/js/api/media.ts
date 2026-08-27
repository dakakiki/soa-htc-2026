import { http } from '@/api/http';
import type { CmsMedia, Paginated } from '@/types/models';

/**
 * `kind` narrows the library to one of the two things it holds (ADR-0053).
 * Omitted, it returns both — which is what the library screen itself wants.
 */
export function listMedia(params: { page?: number; per_page?: number; search?: string; kind?: 'image' | 'document' } = {}) {
    return http.get<Paginated<CmsMedia>>('/api/cms/media', { params });
}

/** Upload a whole selection at once — a library is filled a folder at a time. */
export function uploadMedia(files: File[]) {
    const fd = new FormData();
    files.forEach((file) => fd.append('files[]', file));
    return http.post<{ data: CmsMedia[] }>('/api/cms/media', fd);
}

export function updateMedia(id: number, payload: { alt: string | null }) {
    return http.put<{ data: CmsMedia }>(`/api/cms/media/${id}`, payload);
}

export function deleteMedia(id: number) {
    return http.delete(`/api/cms/media/${id}`);
}
