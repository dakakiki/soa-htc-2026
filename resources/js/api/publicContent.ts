import { http } from '@/api/http';
import type { Paginated, PublicCategory, PublicPage, PublicPost } from '@/types/models';

/**
 * The website's own reads. Unauthenticated, and the server only ever returns
 * published content — see PublicContentController.
 */
export function listPublicPosts(params: { page?: number; per_page?: number; category?: string } = {}) {
    return http.get<Paginated<PublicPost>>('/api/public/posts', { params });
}

export function getPublicPost(slug: string) {
    return http.get<{ data: PublicPost }>(`/api/public/posts/${slug}`);
}

export function getPublicPage(slug: string) {
    return http.get<{ data: PublicPage }>(`/api/public/pages/${slug}`);
}

export function listPublicCategories() {
    return http.get<{ data: PublicCategory[] }>('/api/public/categories');
}
