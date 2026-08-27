import { http } from '@/api/http';

/** A simple content lookup (test type, exam round, question tag). */
export interface Lookup {
    id: number;
    name: string;
    active?: boolean;
    /** Exam rounds only: the position they run in. */
    sort_order?: number;
    /** Exam rounds only: the one being run right now, if any. */
    is_current?: boolean;
}

export interface LookupPayload {
    name: string;
    active?: boolean;
    is_current?: boolean;
}

/** CRUD helpers bound to a lookup resource path. */
function lookupApi(base: string) {
    return {
        list: () => http.get<{ data: Lookup[] }>(`/api/${base}`),
        create: (payload: LookupPayload) => http.post<{ data: Lookup }>(`/api/${base}`, payload),
        update: (id: number, payload: Partial<LookupPayload>) => http.put<{ data: Lookup }>(`/api/${base}/${id}`, payload),
        remove: (id: number) => http.delete(`/api/${base}/${id}`),
    };
}

export const testTypesApi = lookupApi('test-types');
export const examRoundsApi = {
    ...lookupApi('exam-rounds'),
    /**
     * The whole order at once. The server numbers the rounds from this array,
     * so a move is one request and the list can never end up half-renumbered.
     */
    reorder: (ids: number[]) => http.put<{ data: Lookup[] }>('/api/exam-rounds/reorder', { ids }),
};
export const questionTagsApi = lookupApi('question-tags');
