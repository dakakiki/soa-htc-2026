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
    /** The featured image, chosen from the media library. */
    image_media_id?: number | null;
    category_ids?: number[];
}

export function listCmsPosts(params: CmsListParams = {}) {
    return http.get<Paginated<CmsPost>>('/api/cms/posts', { params });
}

export function getCmsPost(id: number) {
    return http.get<{ data: CmsPost }>(`/api/cms/posts/${id}`);
}

export function createCmsPost(payload: CmsPostPayload) {
    return http.post<{ data: CmsPost }>('/api/cms/posts', payload);
}

export function updateCmsPost(id: number, payload: CmsPostPayload) {
    return http.put<{ data: CmsPost }>(`/api/cms/posts/${id}`, payload);
}

export function setCmsPostStatus(id: number, status: string) {
    return http.put<{ data: CmsPost }>(`/api/cms/posts/${id}`, { status });
}

export function deleteCmsPost(id: number) {
    return http.delete(`/api/cms/posts/${id}`);
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
    image_media_id?: number | null;
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
