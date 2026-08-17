import axios from 'axios';

/**
 * Extract a human-readable message from a failed request.
 */
export function apiErrorMessage(error: unknown, fallback = 'Došlo je do greške.'): string {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as { message?: string } | undefined;
        return data?.message ?? fallback;
    }

    return fallback;
}

/**
 * Shared HTTP client for the SPA.
 *
 * Configured for Laravel Sanctum cookie-based auth on a first-party SPA:
 * credentials (session + XSRF cookies) travel with same-origin requests, and
 * axios mirrors the XSRF-TOKEN cookie back as the X-XSRF-TOKEN header.
 */
export const http = axios.create({
    baseURL: '/',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/** Statuses that mean the server session is no longer valid. */
const SESSION_EXPIRED_STATUSES = new Set([401, 419]);

let unauthorizedHandler: (() => void) | null = null;

/**
 * Register a callback fired when any request fails because the session has
 * expired (401 Unauthenticated / 419 Page Expired). Wired in `app.ts` to drop
 * the stale admin identity and redirect to the login screen — without it a
 * cached session keeps rendering the admin shell while every call is rejected.
 */
export function onUnauthorized(handler: () => void): void {
    unauthorizedHandler = handler;
}

http.interceptors.response.use(
    (response) => response,
    (error) => {
        if (axios.isAxiosError(error) && error.response && SESSION_EXPIRED_STATUSES.has(error.response.status)) {
            unauthorizedHandler?.();
        }

        return Promise.reject(error);
    },
);
