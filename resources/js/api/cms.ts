import { http } from '@/api/http';
import type { CmsCategory, CmsPage, CmsPost, Paginated } from '@/types/models';

export interface CmsListParams {
    page?: number;
    per_page?: number;
    search?: string;
    status?: string;
    category_id?: number;
}

/* ---------------------------------------------------------------- categories */

export interface CmsCategoryPayload {
    name: string;
    slug?: string | null;
    parent_id?: number | null;
    description?: string | null;
    status?: string;
    position?: number;
}

export function listCmsCategories(params: CmsListParams = {}) {
    return http.get<Paginated<CmsCategory>>('/api/cms/categories', { params });
}

export function createCmsCategory(payload: CmsCategoryPayload) {
    return http.post<{ data: CmsCategory }>('/api/cms/categories', payload);
}

export function updateCmsCategory(id: number, payload: Partial<CmsCategoryPayload>) {
    return http.put<{ data: CmsCategory }>(`/api/cms/categories/${id}`, payload);
}

export function deleteCmsCategory(id: number) {
    return http.delete(`/api/cms/categories/${id}`);
}

/* --------------------------------------------------------------------- posts */

export interface CmsPostPayload {
    title: string;
    slug?: string | null;
    excerpt?: string | null;
    body?: string | null;
    status?: string;
    published_at?: string | null;
    seo_title?: string | null;
    seo_description?: string | null;
    category_ids?: number[];
}

/** Multipart, because a post carries a cover image. */
function postFormData(payload: CmsPostPayload, image?: File | null): FormData {
    const fd = new FormData();

    Object.entries(payload).forEach(([key, value]) => {
        if (value === null || value === undefined) {
            return;
        }
        if (Array.isArray(value)) {
            // An empty array still has to reach the server as "no categories".
            value.forEach((v) => fd.append(`${key}[]`, String(v)));
            if (value.length === 0) {
                fd.append(`${key}[]`, '');
            }
            return;
        }
        fd.append(key, String(value));
    });

    if (image) {
        fd.append('image', image);
    }

    return fd;
}

export function listCmsPosts(params: CmsListParams = {}) {
    return http.get<Paginated<CmsPost>>('/api/cms/posts', { params });
}

export function getCmsPost(id: number) {
    return http.get<{ data: CmsPost }>(`/api/cms/posts/${id}`);
}

export function createCmsPost(payload: CmsPostPayload, image?: File | null) {
    return http.post<{ data: CmsPost }>('/api/cms/posts', postFormData(payload, image));
}

export function updateCmsPost(id: number, payload: CmsPostPayload, image?: File | null) {
    // Method spoofing: multipart bodies aren't parsed on PUT, so POST with _method.
    const fd = postFormData(payload, image);
    fd.append('_method', 'PUT');
    return http.post<{ data: CmsPost }>(`/api/cms/posts/${id}`, fd);
}

export function setCmsPostStatus(id: number, status: string) {
    return http.put<{ data: CmsPost }>(`/api/cms/posts/${id}`, { status });
}

export function deleteCmsPost(id: number) {
    return http.delete(`/api/cms/posts/${id}`);
}

export function deleteCmsPostImage(id: number) {
    return http.delete<{ data: CmsPost }>(`/api/cms/posts/${id}/image`);
}

/* --------------------------------------------------------------------- pages */

export interface CmsPagePayload {
    title: string;
    slug?: string | null;
    body?: string | null;
    status?: string;
    published_at?: string | null;
    seo_title?: string | null;
    seo_description?: string | null;
}

export function listCmsPages(params: CmsListParams = {}) {
    return http.get<Paginated<CmsPage>>('/api/cms/pages', { params });
}

export function getCmsPage(id: number) {
    return http.get<{ data: CmsPage }>(`/api/cms/pages/${id}`);
}

export function createCmsPage(payload: CmsPagePayload) {
    return http.post<{ data: CmsPage }>('/api/cms/pages', payload);
}

export function updateCmsPage(id: number, payload: Partial<CmsPagePayload>) {
    return http.put<{ data: CmsPage }>(`/api/cms/pages/${id}`, payload);
}

export function deleteCmsPage(id: number) {
    return http.delete(`/api/cms/pages/${id}`);
}
