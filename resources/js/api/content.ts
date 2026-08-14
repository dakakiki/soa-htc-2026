import { http } from '@/api/http';

/** A simple content lookup (test type, exam round, question tag). */
export interface Lookup {
    id: number;
    name: string;
    active?: boolean;
}

export interface LookupPayload {
    name: string;
    active?: boolean;
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
export const examRoundsApi = lookupApi('exam-rounds');
export const questionTagsApi = lookupApi('question-tags');
