import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import * as studentApi from '@/api/student';
import type { IdentifyPayload } from '@/api/student';
import type { StudentRegistrationSummary } from '@/types/models';

const STORAGE_KEY = 'student-token';

/**
 * The short-lived competitor web session (Slice 3b/3c), kept separate from the
 * admin/coordinator session. The bearer token persists in localStorage so a
 * refresh keeps the competitor signed in until it expires or is revoked.
 */
export const useStudentSessionStore = defineStore('studentSession', () => {
    const token = ref<string | null>(localStorage.getItem(STORAGE_KEY));
    const registration = ref<StudentRegistrationSummary | null>(null);
    const expiresAt = ref<string | null>(null);
    const ready = ref(false);

    const isIdentified = computed(() => token.value !== null && registration.value !== null);

    function setToken(value: string | null): void {
        token.value = value;
        if (value !== null) {
            localStorage.setItem(STORAGE_KEY, value);
        } else {
            localStorage.removeItem(STORAGE_KEY);
        }
    }

    async function identify(payload: IdentifyPayload): Promise<void> {
        const { data } = await studentApi.identify(payload);
        setToken(data.token);
        registration.value = data.registration;
        expiresAt.value = data.expires_at;
        ready.value = true;
    }

    /**
     * Resolve the stored token once (used by the router guard). An expired or
     * revoked token answers 401, which we treat as "not identified".
     */
    async function ensureLoaded(): Promise<void> {
        if (ready.value) {
            return;
        }
        if (token.value === null) {
            ready.value = true;
            return;
        }

        try {
            const { data } = await studentApi.me(token.value);
            registration.value = data.registration;
            expiresAt.value = data.expires_at;
        } catch {
            setToken(null);
            registration.value = null;
        } finally {
            ready.value = true;
        }
    }

    async function logout(): Promise<void> {
        if (token.value !== null) {
            try {
                await studentApi.logout(token.value);
            } catch {
                // The token may already be gone; clear locally regardless.
            }
        }
        setToken(null);
        registration.value = null;
        expiresAt.value = null;
    }

    return { token, registration, expiresAt, ready, isIdentified, identify, ensureLoaded, logout };
});
