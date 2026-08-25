import { http } from '@/api/http';
import type {
    AttemptSession,
    AttemptSummary,
    AvailabilityQuiz,
    Country,
    StudentIdentifyResult,
    StudentRegistrationSummary,
    SubmitAnswer,
} from '@/types/models';

/**
 * Student endpoints authenticate with the bearer token issued at identification
 * (not the admin Sanctum cookie), so the token is attached per request.
 */
const auth = (token: string) => ({ headers: { Authorization: `Bearer ${token}` } });

export interface IdentifyPayload {
    competitor_number: string;
    country_id: number;
    date_of_birth: string;
}

export function identify(payload: IdentifyPayload) {
    return http.post<StudentIdentifyResult>('/api/student/identify', payload);
}

export function me(token: string) {
    return http.get<{ expires_at: string; registration: StudentRegistrationSummary }>('/api/student/me', auth(token));
}

export function logout(token: string) {
    return http.post('/api/student/logout', {}, auth(token));
}

/** Public country list for the identification form. */
export function listCountries() {
    return http.get<{ data: Country[] }>('/api/student/countries');
}

export function availability(token: string) {
    return http.get<{ quizzes: AvailabilityQuiz[] }>('/api/student/availability', auth(token));
}

/**
 * Clear a competition quiz's exam password. Called from ONE place — the entry
 * form's competition stream ({@see useStudentSessionStore.enterCompetition}) —
 * because the password is read out in the room once and given once. The tests
 * screen used to ask for it a second time; the owner's rule (2026-08-25) is that
 * "that option does not exist", so do not put a form for this on a screen again.
 */
export function unlockQuiz(token: string, quizId: number, password: string) {
    return http.post<{ unlocked: boolean }>(`/api/student/quizzes/${quizId}/unlock`, { password }, auth(token));
}

/** Start (or resume) the single attempt at a test. */
export function startTest(token: string, testId: number) {
    return http.post<AttemptSession>(`/api/student/tests/${testId}/start`, {}, auth(token));
}

export function getAttempt(token: string, attemptId: number) {
    return http.get<AttemptSession>(`/api/student/attempts/${attemptId}`, auth(token));
}

export function submitAttempt(token: string, attemptId: number, answers: SubmitAnswer[]) {
    return http.post<{ attempt: AttemptSummary }>(`/api/student/attempts/${attemptId}/submit`, { answers }, auth(token));
}
