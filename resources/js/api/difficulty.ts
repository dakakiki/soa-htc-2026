import { http } from '@/api/http';
import type { DifficultyCategory, DifficultyLevel } from '@/types/models';

export interface CategoryPayload {
    name: string;
    type: string;
    countries_all: boolean;
    country_ids?: number[];
    status?: string;
}

export interface LevelPayload {
    difficulty_category_id: number;
    name: string;
    level_short: string;
    grades: number[];
    position?: number;
    status?: string;
}

export function listCategories() {
    return http.get<{ data: DifficultyCategory[] }>('/api/difficulty-categories');
}
export function createCategory(payload: CategoryPayload) {
    return http.post<{ data: DifficultyCategory }>('/api/difficulty-categories', payload);
}
export function updateCategory(id: number, payload: Partial<CategoryPayload>) {
    return http.put<{ data: DifficultyCategory }>(`/api/difficulty-categories/${id}`, payload);
}
export function setCategoryStatus(id: number, status: string) {
    return http.put<{ data: DifficultyCategory }>(`/api/difficulty-categories/${id}`, { status });
}
export function deleteCategory(id: number) {
    return http.delete(`/api/difficulty-categories/${id}`);
}

export function listLevels(categoryId: number) {
    return http.get<{ data: DifficultyLevel[] }>('/api/difficulty-levels', {
        params: { difficulty_category_id: categoryId },
    });
}
export function createLevel(payload: LevelPayload) {
    return http.post<{ data: DifficultyLevel }>('/api/difficulty-levels', payload);
}
export function updateLevel(id: number, payload: Partial<LevelPayload>) {
    return http.put<{ data: DifficultyLevel }>(`/api/difficulty-levels/${id}`, payload);
}
export function setLevelStatus(id: number, status: string) {
    return http.put<{ data: DifficultyLevel }>(`/api/difficulty-levels/${id}`, { status });
}
export function deleteLevel(id: number) {
    return http.delete(`/api/difficulty-levels/${id}`);
}
