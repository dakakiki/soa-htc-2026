import { http } from '@/api/http';
import type { Paginated, Registration, ResultColumn, ResultDetailRound } from '@/types/models';

export interface RegistrationPayload {
    school_id: number;
    school_external?: string | null;
    difficulty_level_id: number;
    name: string;
    date_of_birth?: string | null;
    grade: number;
    status?: string;
    attendance?: string;
}

export interface RegistrationListParams {
    page?: number;
    search?: string;
    country_id?: number;
    region_id?: number;
    school_id?: number;
    level_id?: number;
    grade?: number;
    exam_round_id?: number;
    status?: string;
    attendance?: string;
    per_page?: number;
}

export function listRegistrations(params: RegistrationListParams = {}) {
    return http.get<Paginated<Registration>>('/api/registrations', { params });
}

/** Download the currently filtered students roster as .xlsx (same filters as the list). */
export function exportRegistrations(params: RegistrationListParams = {}) {
    return http.get('/api/registrations/export', { params, responseType: 'blob' });
}

/** Download the printable attendance register (PDF) for one venue + difficulty levels. */
export function attendanceReport(params: { school_id: number; level_id: number[] }) {
    return http.get('/api/registrations/attendance-report', { params, responseType: 'blob' });
}

/** The chunk plan for a SOA Cert run: student count and how many part PDFs to download. */
export interface SoaCertPlan {
    total: number;
    chunk_size: number;
    chunks: number;
}

export function soaCertificatePlan(params: { round: string; school_id: number; level_id: number[] }) {
    return http.get<SoaCertPlan>('/api/registrations/soa-certificate/plan', { params });
}

/** Download one part (zero-based `chunk`) of the SOA participation certificates (PDF). */
export function soaCertificate(params: { round: string; school_id: number; level_id: number[]; chunk: number }) {
    return http.get('/api/registrations/soa-certificate', { params, responseType: 'blob' });
}

/** The difficulty-category sets a competitor at this country can be imported into. */
export function importCategorySets(countryId: number) {
    return http.get<{ data: { id: number; label: string }[] }>(
        '/api/registrations/import/category-sets', { params: { country_id: countryId } },
    );
}

/** Download the "Upload Students" .xlsx template. */
export function importTemplate() {
    return http.get('/api/registrations/import/template', { responseType: 'blob' });
}

export interface StudentImportSummary {
    created: number;
    error_count: number;
}

/** Bulk-create students for one venue from an .xlsx upload. Rejects the whole file on any bad row. */
export function importStudents(payload: { school_id: number; category_id: number; file: File }) {
    const fd = new FormData();
    fd.append('school_id', String(payload.school_id));
    fd.append('category_id', String(payload.category_id));
    fd.append('file', payload.file);
    return http.post<StudentImportSummary>('/api/registrations/import', fd);
}

/** Download the uploaded file back with an "Error" column filled in per invalid row. */
export function importStudentErrors(payload: { school_id: number; category_id: number; file: File }) {
    const fd = new FormData();
    fd.append('school_id', String(payload.school_id));
    fd.append('category_id', String(payload.category_id));
    fd.append('file', payload.file);
    return http.post('/api/registrations/import/errors', fd, { responseType: 'blob' });
}

/** Download the attendance-update .xlsx template (Candidate no | Absent). */
export function attendanceImportTemplate() {
    return http.get('/api/registrations/attendance-import/template', { responseType: 'blob' });
}

export interface AttendanceImportSummary {
    updated: number;
    not_found: number;
    invalid: number;
    not_found_numbers: string[];
}

/** Bulk-update attendance from an .xlsx upload (Candidate no | Absent), keyed by competitor number. */
export function importAttendance(file: File) {
    const fd = new FormData();
    fd.append('file', file);
    return http.post<AttendanceImportSummary>('/api/registrations/attendance-import', fd);
}

export function getRegistration(id: number) {
    return http.get<{ data: Registration }>(`/api/registrations/${id}`);
}

export function createRegistration(payload: RegistrationPayload) {
    return http.post<{ data: Registration }>('/api/registrations', payload);
}

export function updateRegistration(id: number, payload: Partial<RegistrationPayload>) {
    return http.put<{ data: Registration }>(`/api/registrations/${id}`, payload);
}

export function setRegistrationStatus(id: number, status: string) {
    return http.put<{ data: Registration }>(`/api/registrations/${id}`, { status });
}

export function deleteRegistration(id: number) {
    return http.delete(`/api/registrations/${id}`);
}

/** Results-grid column definition (rounds and their test-type heads). */
export function resultColumns() {
    return http.get<{ data: ResultColumn[] }>('/api/registrations/result-columns');
}

/** One competitor's published results grouped by round, for the details modal. */
export function registrationResults(id: number) {
    return http.get<{ data: ResultDetailRound[] }>(`/api/registrations/${id}/results`);
}
