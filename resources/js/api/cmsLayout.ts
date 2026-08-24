import { http } from '@/api/http';
import type { CmsLayoutBlock, LayoutRegistry } from '@/types/models';

/**
 * The layout editor (ADR-0043). Zones are not created here — they come from the
 * registry below, which the server builds from code.
 */
export function getLayoutRegistry() {
    return http.get<{ data: LayoutRegistry }>('/api/cms/layout/zones');
}

export function listLayoutBlocks(zone: string) {
    return http.get<{ data: CmsLayoutBlock[] }>(`/api/cms/layout/${zone}`);
}

export interface LayoutBlockPayload {
    type?: string;
    status?: boolean;
    content?: Record<string, unknown>;
    image_media_id?: number | null;
}

export function createLayoutBlock(zone: string, payload: LayoutBlockPayload) {
    return http.post<{ data: CmsLayoutBlock }>(`/api/cms/layout/${zone}/blocks`, payload);
}

export function updateLayoutBlock(id: number, payload: LayoutBlockPayload) {
    return http.put<{ data: CmsLayoutBlock }>(`/api/cms/layout-blocks/${id}`, payload);
}

export function deleteLayoutBlock(id: number) {
    return http.delete(`/api/cms/layout-blocks/${id}`);
}

/**
 * The order goes as the whole list, in one request — dragging one section
 * rewrites the position of several, and the server refuses a list that does not
 * cover exactly this zone.
 */
export function saveLayoutOrder(zone: string, blocks: number[]) {
    return http.put<{ data: CmsLayoutBlock[] }>(`/api/cms/layout/${zone}/order`, { blocks });
}
