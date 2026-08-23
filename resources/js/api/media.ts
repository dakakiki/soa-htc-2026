import { http } from '@/api/http';
import type { CmsMedia, Paginated } from '@/types/models';

export function listMedia(params: { page?: number; per_page?: number; search?: string } = {}) {
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
