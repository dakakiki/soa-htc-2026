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
