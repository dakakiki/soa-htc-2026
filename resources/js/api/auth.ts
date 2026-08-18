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

export async function fetchUser(): Promise<AuthUser> {
    const { data } = await http.get<{ data: AuthUser }>('/api/auth/user');

    return data.data;
}
