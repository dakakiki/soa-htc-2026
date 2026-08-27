import { http } from '@/api/http';
import type { CoordinatorRegistration, Paginated } from '@/types/models';

/**
 * The coordinator registration queue (ADR-0053) — the admin half. The public
 * half (sending one) lives in `publicContent.ts`, with the rest of what an
 * anonymous visitor can reach.
 */
export interface RegistrationQueueParams {
    page?: number;
    per_page?: number;
    status?: string;
    country_id?: number;
    search?: string;
}

export function listCoordinatorRegistrations(params: RegistrationQueueParams = {}) {
    return http.get<Paginated<CoordinatorRegistration>>('/api/coordinator-registrations', { params });
}

export function getCoordinatorRegistration(id: number) {
    return http.get<{ data: CoordinatorRegistration }>(`/api/coordinator-registrations/${id}`);
}

/**
 * The signed venue approval. A blob, not a URL: the document sits on the private
 * disk behind the same permission that decides the application, so there is no
 * address to link to.
 */
export function downloadApprovalDocument(id: number) {
    return http.get(`/api/coordinator-registrations/${id}/document`, { responseType: 'blob' });
}

export function approveCoordinatorRegistration(id: number) {
    return http.post<{ data: CoordinatorRegistration }>(`/api/coordinator-registrations/${id}/approve`);
}

export function declineCoordinatorRegistration(id: number, reason: string | null) {
    return http.post<{ data: CoordinatorRegistration }>(`/api/coordinator-registrations/${id}/decline`, { reason });
}

export function deleteCoordinatorRegistration(id: number) {
    return http.delete(`/api/coordinator-registrations/${id}`);
}

/** How many are waiting — the badge on the menu item. */
export function pendingRegistrationCount() {
    return http.get<{ data: { pending: number } }>('/api/coordinator-registrations/pending-count');
}
