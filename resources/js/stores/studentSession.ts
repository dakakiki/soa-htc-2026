import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import * as studentApi from '@/api/student';
import type { IdentifyPayload } from '@/api/student';
import type { StudentRegistrationSummary } from '@/types/models';

const STORAGE_KEY = 'student-token';
const MODE_KEY = 'student-mode';

/** Which exam stream the competitor entered through. */
export type EntryMode = 'sample' | 'competition';

/**
 * The short-lived competitor web session (Slice 3b/3c), kept separate from the
 * admin/coordinator session. The bearer token persists in localStorage so a
 * refresh keeps the competitor signed in until it expires or is revoked.
 */
export const useStudentSessionStore = defineStore('studentSession', () => {
    const token = ref<string | null>(localStorage.getItem(STORAGE_KEY));
    const registration = ref<StudentRegistrationSummary | null>(null);
    const expiresAt = ref<string | null>(null);
    const mode = ref<EntryMode | null>(localStorage.getItem(MODE_KEY) as EntryMode | null);
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

    function setMode(value: EntryMode | null): void {
        mode.value = value;
        if (value !== null) {
            localStorage.setItem(MODE_KEY, value);
        } else {
            localStorage.removeItem(MODE_KEY);
        }
    }

    async function identify(payload: IdentifyPayload): Promise<void> {
        const { data } = await studentApi.identify(payload);
        setToken(data.token);
        registration.value = data.registration;
        expiresAt.value = data.expires_at;
        ready.value = true;
    }

    /** Sample stream: identify only, no password. */
    async function enterSample(payload: IdentifyPayload): Promise<void> {
        await identify(payload);
        setMode('sample');
    }

    /**
     * Competition stream: identify, then clear the exam password on the level's
     * competition quiz(es). Any failure (wrong details OR wrong password) rolls
     * the session back and throws uniformly, so nothing leaks that the three
     * factors were correct.
     */
    async function enterCompetition(payload: IdentifyPayload, password: string): Promise<void> {
        try {
            await identify(payload);
            const { data } = await studentApi.availability(token.value ?? '');
            const competition = data.quizzes.filter((q) => q.mode === 'competition');
            const gated = competition.filter((q) => q.requires_password && !q.unlocked);

            let ok = competition.length > 0 && gated.length === 0;
            for (const quiz of gated) {
                try {
                    await studentApi.unlockQuiz(token.value ?? '', quiz.id, password);
                    ok = true;
                } catch {
                    // Try the next gated quiz; a single match is enough.
                }
            }
            if (!ok) {
                throw new Error('denied');
            }
            setMode('competition');
        } catch (error) {
            await logout();
            throw error;
        }
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
        setMode(null);
        registration.value = null;
        expiresAt.value = null;
    }

    return { token, registration, expiresAt, mode, ready, isIdentified, identify, enterSample, enterCompetition, ensureLoaded, logout };
});
