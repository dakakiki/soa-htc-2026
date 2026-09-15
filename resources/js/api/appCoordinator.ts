import { http } from '@/api/http';

/**
 * The installed application's coordinator reads (prototype 7-9d).
 *
 * 🔴 Everything here is bounded server-side by the venues the signed-in person
 * holds in the active season — the client never sends a scope and could not
 * widen one if it tried. See `Api\App\CoordinatorController`.
 *
 * 🪤 There is no exam password in any of these shapes, and that is not an
 * omission: `quizzes.quiz_password` is a bcrypt hash, so the server can check a
 * password and cannot read one back. The owner's decision of 2026-09-15 was to
 * drop the prototype's password card rather than store the password reversibly,
 * so there is nothing here to add later.
 */

/** One paper, as a coordinator's room sees it. */
export interface CoordinatorPaper {
    test_id: number;
    quiz: string;
    round: string | null;
    exam: string;
    test: string;
    type: string | null;
    questions: number;
    entered: number;
    submitted: number;
    /** Open papers only: how far the room has got, and how long the paper runs. */
    started?: number;
    duration?: number | null;
    /** Published papers only — an open paper's average is a moving number. */
    average?: number;
}

export interface CoordinatorVenue {
    id: number;
    name: string;
    city: string | null;
}

export interface CoordinatorHome {
    name: string;
    /** `school_coordinator`, `country_coordinator` or `admin`. */
    role: string;
    /** The one venue they hold, or null when they hold several. */
    venue: CoordinatorVenue | null;
    venues_count: number;
    /** Typed with the season record, never inferred (ADR-0081). */
    round: number | null;
    season: string | null;
    open: CoordinatorPaper[];
    published: CoordinatorPaper[];
}

export function coordinatorHome() {
    return http.get<{ data: CoordinatorHome }>('/api/app/coordinator/home');
}

export function coordinatorVenues(q = '') {
    return http.get<{ data: CoordinatorVenue[] }>('/api/app/coordinator/venues', { params: q === '' ? {} : { q } });
}

export function venueFigures(venueId: number) {
    return http.get<{ data: { venue: CoordinatorVenue; venues_count: number; figures: CoordinatorPaper[] } }>(
        `/api/app/coordinator/venues/${venueId}/figures`,
    );
}
