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

/**
 * The three ways a coordinator can look at one venue, in the words the server
 * slices by — one vocabulary from the query string to the heading.
 *
 * 🔴 `upcoming` is NOT a timetable. Nothing in this system dates an exam: a test
 * carries a duration and a status, and the only dates in the database belong to
 * the season. It means *open, and this room has not begun it*, which is the only
 * thing the data can say.
 *
 * 🔴 `running` is started AND not yet published. Drop the second half and a
 * marked paper stands under two headings at once.
 */
export type CoordinatorSlice = 'upcoming' | 'running' | 'published';

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
    /** The header names the country above the school (owner, 2026-09-18). */
    country?: string | null;
    /** On the list of venues: how many papers this one holds in the way in asked for. */
    papers?: number;
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
    /**
     * 🔴 How many papers each way in holds — and `null` for somebody who runs
     * more than one venue. A number beside a way in has to be a number about a
     * room; summed across two dozen venues it is about no room at all, and a
     * number like that on the first screen is what made the old one unreadable.
     */
    counts: Record<CoordinatorSlice, number> | null;
}

export interface CoordinatorFigures {
    venue: CoordinatorVenue;
    slice: CoordinatorSlice;
    venues_count: number;
    papers: CoordinatorPaper[];
}

export function coordinatorHome() {
    return http.get<{ data: CoordinatorHome }>('/api/app/coordinator/home');
}

export function coordinatorVenues(slice: CoordinatorSlice, q = '') {
    return http.get<{ data: CoordinatorVenue[] }>('/api/app/coordinator/venues', {
        params: q === '' ? { slice } : { slice, q },
    });
}

export function venueFigures(venueId: number, slice: CoordinatorSlice) {
    return http.get<{ data: CoordinatorFigures }>(
        `/api/app/coordinator/venues/${venueId}/figures`,
        { params: { slice } },
    );
}
