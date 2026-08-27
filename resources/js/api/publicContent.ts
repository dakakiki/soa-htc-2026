import { http } from '@/api/http';
import type {
    Country,
    Paginated,
    PublicBlock,
    PublicCategory,
    PublicMenu,
    PublicPage,
    PublicPost,
    SiteStatus,
} from '@/types/models';

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

/** A navigation, already resolved to labels and addresses. */
export function getPublicMenu(slug: string) {
    return http.get<{ data: PublicMenu }>(`/api/public/menus/${slug}`);
}

/**
 * The sections of a layout zone. Blocks and buttons the visitor must not see
 * never arrive, so nothing here has to be filtered again.
 */
export function getPublicLayout(zone: string) {
    return http.get<{ data: { zone: string; blocks: PublicBlock[] } }>(`/api/public/layout/${zone}`);
}

/** Which round is running and whether it is open — both derived server-side. */
export function getSiteStatus() {
    return http.get<{ data: SiteStatus }>('/api/public/site');
}

/** The country list the registration form picks from. Reference data, unpaginated. */
export function listPublicCountries() {
    return http.get<{ data: Country[] }>('/api/public/countries');
}

/**
 * Send a coordinator registration (ADR-0053). Multipart, because the signed
 * venue approval travels with it.
 *
 * Nothing comes back but an acknowledgement: no account exists yet, so there is
 * no session to start and no token to keep.
 */
export function submitCoordinatorRegistration(form: FormData) {
    return http.post<{ data: { received: boolean } }>('/api/public/coordinator-registrations', form);
}
