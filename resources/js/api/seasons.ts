import { http } from '@/api/http';
import type { Season, SeasonSettings } from '@/types/models';

/** The active season, what the next one would clear, and the values to prefill. */
export function getSeasonSettings() {
    return http.get<SeasonSettings>('/api/settings/season');
}

export interface StartSeasonPayload {
    name: string;
    year: number;
    round_number: number;
    starts_at: string | null;
    ends_at: string | null;
    /** The acknowledgement, not a preference — the server refuses without it. */
    confirm: true;
}

/**
 * Archive the outgoing season, wipe it and make the new round active. One
 * transaction on the server; there is no undo on either side.
 */
export function startSeason(payload: StartSeasonPayload) {
    return http.post<{ season: Season; applied: Record<string, number> }>('/api/settings/season', payload);
}
