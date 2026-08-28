import { http } from '@/api/http';
import type { AuthUser } from '@/types/models';

/**
 * Prime the XSRF-TOKEN cookie before any state-changing request.
 */
export async function csrf(): Promise<void> {
    await http.get('/sanctum/csrf-cookie');
}

export async function login(email: string, password: string, remember = false): Promise<AuthUser> {
    await csrf();
    const { data } = await http.post<{ data: AuthUser }>('/api/auth/login', { email, password, remember });

    return data.data;
}

export async function logout(): Promise<void> {
    await http.post('/api/auth/logout');
}

/**
 * Ask for a link to set a new password (ADR-0063).
 *
 * Resolves the same way whether or not there is an account behind the address —
 * the server will not say, so neither can this. The screen that calls it says
 * "if there is an account" for the same reason.
 */
export async function requestPasswordResetLink(email: string): Promise<void> {
    await csrf();
    await http.post('/api/auth/forgot-password', { email });
}

/** Spend a link and set the password. Rejects when the link is expired or spent. */
export async function resetPassword(payload: {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
}): Promise<void> {
    await csrf();
    await http.post('/api/auth/reset-password', payload);
}

export async function fetchUser(): Promise<AuthUser> {
    const { data } = await http.get<{ data: AuthUser }>('/api/auth/user');

    return data.data;
}
