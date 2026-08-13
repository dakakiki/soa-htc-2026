import { defineStore } from 'pinia';
import { ref } from 'vue';
import { http } from '@/api/http';

export interface AuthUser {
    id: number;
    name: string;
    email: string;
}

/**
 * Holds the authenticated admin/coordinator user for the SPA.
 * Student access uses a separate short-lived session and is modelled later.
 */
export const useSessionStore = defineStore('session', () => {
    const user = ref<AuthUser | null>(null);
    const loading = ref(false);

    async function fetchUser(): Promise<void> {
        loading.value = true;
        try {
            const { data } = await http.get<AuthUser>('/api/user');
            user.value = data;
        } catch {
            user.value = null;
        } finally {
            loading.value = false;
        }
    }

    return { user, loading, fetchUser };
});
