import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import * as authApi from '@/api/auth';
import type { AuthUser } from '@/types/models';

/**
 * Authenticated admin/coordinator identity for the SPA. Student access uses a
 * separate short-lived session modelled later.
 */
export const useSessionStore = defineStore('session', () => {
    const user = ref<AuthUser | null>(null);
    const ready = ref(false);

    const isAuthenticated = computed(() => user.value !== null);

    function can(permission: string): boolean {
        return user.value?.permissions.includes(permission) ?? false;
    }

    /**
     * Resolve the current user once (used by the router guard on first load).
     */
    async function ensureLoaded(): Promise<void> {
        if (ready.value) {
            return;
        }

        try {
            user.value = await authApi.fetchUser();
        } catch {
            user.value = null;
        } finally {
            ready.value = true;
        }
    }

    /** Re-read the identity after the user edits their own profile. */
    async function refresh(): Promise<void> {
        user.value = await authApi.fetchUser();
    }

    async function login(email: string, password: string, remember = false): Promise<void> {
        user.value = await authApi.login(email, password, remember);
        ready.value = true;
    }

    async function logout(): Promise<void> {
        await authApi.logout();
        user.value = null;
    }

    /**
     * Drop the identity locally without calling the API — used when the server
     * has already reported the session expired (a 401/419), so there is nothing
     * left to log out on the backend.
     */
    function forceLogout(): void {
        user.value = null;
    }

    return { user, ready, isAuthenticated, can, ensureLoaded, refresh, login, logout, forceLogout };
});
