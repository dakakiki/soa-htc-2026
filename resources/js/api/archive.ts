import { http } from '@/api/http';

/** One archived round in the picker. */
export interface ArchiveRound {
    round: number;
    year: number | null;
    registered: number;
    participated: number;
}

/** One row in a registered-vs-participated breakdown (country / region / school). */
export interface ArchiveBreakdownRow {
    name: string;
    registered: number;
    participated: number;
}

export interface ArchiveTable {
    rows: ArchiveBreakdownRow[];
    truncated: boolean;
}

export interface ArchiveDistribution {
    label: string | number | null;
    count: number;
}

export interface ArchiveSummary {
    round: number;
    totals: { registered: number; participated: number; qualifications: number };
    breakdown: ArchiveTable & { dimension: 'country' | 'region' };
    by_school: ArchiveTable;
    by_level: ArchiveDistribution[];
    by_grade: ArchiveDistribution[];
    filters: { countries: string[]; regions: string[]; schools: string[]; levels: string[] };
}

/** The archived rounds available to browse (newest first). */
export function archiveRounds() {
    return http.get<{ rounds: ArchiveRound[] }>('/api/archive/rounds');
}

/** One round's archive summary, optionally narrowed by country → region → school and level. */
export function archiveSummary(params: { round: number; country?: string | null; region?: string | null; school?: string | null; level?: string | null }) {
    const clean = Object.fromEntries(
        Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '')
    );
    return http.get<ArchiveSummary>('/api/archive/summary', { params: clean });
}
