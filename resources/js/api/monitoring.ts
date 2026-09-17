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
