import { http } from '@/api/http';
import type { AvailabilityQuiz, Country, StudentIdentifyResult, StudentRegistrationSummary } from '@/types/models';

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

export function unlockQuiz(token: string, quizId: number, password: string) {
    return http.post<{ unlocked: boolean }>(`/api/student/quizzes/${quizId}/unlock`, { password }, auth(token));
}
