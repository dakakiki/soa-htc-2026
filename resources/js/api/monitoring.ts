import { http } from '@/api/http';

/** One line of the trail, as the User log screen reads it. */
export interface UserLogEntry {
    id: number;
    created_at: string | null;
    actor_id: number | null;
    /** Kept beside the id, so a deleted account is still named. */
    actor_label: string | null;
    action: string;
    subject: string;
    ip_address: string | null;
    /** One line saying what the row is about; the full payload is below it. */
    details: string;
    reason: string | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
}

export interface UserLogParams {
    page?: number;
    per_page?: number;
    actor_id?: number | null;
    action?: string | null;
    from?: string | null;
    to?: string | null;
    q?: string | null;
}

export interface UserLogOptions {
    actions: string[];
    actors: { id: number; label: string | null }[];
}

function clean(params: UserLogParams): Record<string, string | number> {
    const out: Record<string, string | number> = {};
    Object.entries(params).forEach(([k, v]) => {
        if (v !== null && v !== undefined && v !== '') {
            out[k] = v as string | number;
        }
    });
    return out;
}

export function listUserLog(params: UserLogParams = {}) {
    return http.get<{
        data: UserLogEntry[];
        meta: { current_page: number; last_page: number; per_page: number; total: number };
    }>('/api/monitoring/user-log', { params: clean(params) });
}

/** The actions and people that actually occur, read from the trail itself. */
export function userLogOptions() {
    return http.get<{ data: UserLogOptions }>('/api/monitoring/user-log/options');
}

/** The filtered trail as .xlsx. Same filters as the list. */
export function exportUserLog(params: UserLogParams = {}) {
    return http.get('/api/monitoring/user-log/export', { params: clean(params), responseType: 'blob' });
}

/** One competitor sitting an exam right now. */
export interface CurrentActionRow {
    id: number;
    registration_id: number;
    competitor_number: string;
    name: string | null;
    country: string | null;
    venue: string | null;
    level: string | null;
    quiz: string | null;
    test: string | null;
    started_at: string | null;
    expires_at: string | null;
    answered: number;
    /** Past the deadline and still open: a closed browser, not somebody working. */
    overdue: boolean;
}

export interface CurrentAction {
    /** The server's clock at the moment of the answer; the countdown runs off this. */
    as_of: string;
    counts: {
        running: number;
        overdue: number;
        submitted_recently: number;
        venues: number;
        recent_minutes: number;
    };
    by_exam: { test_id: number; test: string; n: number }[];
    rows: CurrentActionRow[];
}

export function currentAction(q?: string) {
    return http.get<{ data: CurrentAction }>('/api/monitoring/current-action', {
        params: q ? { q } : {},
    });
}
