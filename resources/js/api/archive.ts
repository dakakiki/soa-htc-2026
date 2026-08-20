import { http } from '@/api/http';

/** One archived round in the picker. */
export interface ArchiveRound {
    round: number;
    year: number | null;
    registered: number;
    participated: number;
}

export interface ArchiveCountryRow {
    country: string | null;
    registered: number;
    participated: number;
}

export interface ArchiveSchoolRow {
    school: string;
    registered: number;
    participated: number;
}

export interface ArchiveDistribution {
    label: string | number | null;
    count: number;
}

export interface ArchiveSummary {
    round: number;
    totals: { registered: number; participated: number; qualifications: number };
    per_country: ArchiveCountryRow[];
    by_school: { rows: ArchiveSchoolRow[]; truncated: boolean };
    by_level: ArchiveDistribution[];
    by_grade: ArchiveDistribution[];
    filters: { countries: string[]; levels: string[]; schools: string[] };
}

/** The archived rounds available to browse (newest first). */
export function archiveRounds() {
    return http.get<{ rounds: ArchiveRound[] }>('/api/archive/rounds');
}

/** One round's archive summary, optionally narrowed by country/level/school. */
export function archiveSummary(params: { round: number; country?: string | null; level?: string | null; school?: string | null }) {
    const clean = Object.fromEntries(
        Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '')
    );
    return http.get<ArchiveSummary>('/api/archive/summary', { params: clean });
}
