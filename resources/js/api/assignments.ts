import { http } from '@/api/http';
import type { Assignment } from '@/types/models';

export interface AssignmentPayload {
    role_id: number;
    season_id?: number | null;
    status?: string;
    school_ids?: number[];
}

export function createAssignment(userId: number, payload: AssignmentPayload) {
    return http.post<{ data: Assignment }>(`/api/users/${userId}/assignments`, payload);
}

export function deleteAssignment(id: number) {
    return http.delete(`/api/assignments/${id}`);
}
