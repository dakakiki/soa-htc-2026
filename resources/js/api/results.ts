import { http } from '@/api/http';

export interface PublishTest {
    id: number;
    title: string;
    completed: number;
    published: number;
    pending: number;
}

export interface PublishExam {
    id: number;
    title: string;
    tests: PublishTest[];
}

export interface PublishQuiz {
    id: number;
    title: string;
    exams: PublishExam[];
}

export function overview() {
    return http.get<{ quizzes: PublishQuiz[] }>('/api/results/overview');
}

export function publish(payload: { scope: 'test' | 'exam'; id: number; unpublish?: boolean }) {
    return http.post<{ action: string; attempts_count: number }>('/api/results/publish', payload);
}
