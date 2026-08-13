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

    async function login(email: string, password: string): Promise<void> {
        user.value = await authApi.login(email, password);
        ready.value = true;
    }

    async function logout(): Promise<void> {
        await authApi.logout();
        user.value = null;
    }

    return { user, ready, isAuthenticated, can, ensureLoaded, login, logout };
});
