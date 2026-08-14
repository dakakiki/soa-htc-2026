import { http } from '@/api/http';
import type { Paginated, Question, QuestionAnswer } from '@/types/models';

export interface QuestionPayload {
    title: string;
    description?: string | null;
    question_type: string;
    points: number;
    question_tag_id?: number | null;
    status?: string;
    level_ids: number[];
    answers: QuestionAnswer[];
}

export interface QuestionFiles {
    image?: File | null;
    audio?: File | null;
}

export interface QuestionListParams {
    page?: number;
    search?: string;
    question_type?: string;
    tag_id?: number;
    level_id?: number;
    status?: string;
    per_page?: number;
}

export function listQuestions(params: QuestionListParams = {}) {
    return http.get<Paginated<Question>>('/api/questions', { params });
}

export function getQuestion(id: number) {
    return http.get<{ data: Question }>(`/api/questions/${id}`);
}

function toFormData(payload: Partial<QuestionPayload>, files?: QuestionFiles): FormData {
    const fd = new FormData();
    const append = (key: string, value: unknown) => {
        if (value !== null && value !== undefined) {
            fd.append(key, String(value));
        }
    };
    append('title', payload.title);
    append('description', payload.description);
    append('question_type', payload.question_type);
    append('points', payload.points);
    append('question_tag_id', payload.question_tag_id);
    append('status', payload.status);
    (payload.level_ids ?? []).forEach((id) => fd.append('level_ids[]', String(id)));
    (payload.answers ?? []).forEach((a, i) => {
        fd.append(`answers[${i}][text]`, a.text);
        fd.append(`answers[${i}][is_correct]`, a.is_correct ? '1' : '0');
        fd.append(`answers[${i}][position]`, String(a.position ?? i + 1));
    });
    if (files?.image) fd.append('image', files.image);
    if (files?.audio) fd.append('audio', files.audio);
    return fd;
}

export function createQuestion(payload: QuestionPayload, files?: QuestionFiles) {
    return http.post<{ data: Question }>('/api/questions', toFormData(payload, files));
}

export function updateQuestion(id: number, payload: Partial<QuestionPayload>, files?: QuestionFiles) {
    const fd = toFormData(payload, files);
    fd.append('_method', 'PUT');
    return http.post<{ data: Question }>(`/api/questions/${id}`, fd);
}

export function setQuestionStatus(id: number, status: string) {
    return http.put<{ data: Question }>(`/api/questions/${id}`, { status });
}

export function deleteQuestion(id: number) {
    return http.delete(`/api/questions/${id}`);
}
