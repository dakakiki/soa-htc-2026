import { http } from '@/api/http';
import type { CmsMenu, CmsMenuItemPayload, CmsMenuTarget } from '@/types/models';

export function listMenus() {
    return http.get<{ data: CmsMenu[] }>('/api/cms/menus');
}

export function getMenu(id: number) {
    return http.get<{ data: CmsMenu }>(`/api/cms/menus/${id}`);
}

export function createMenu(name: string) {
    return http.post<{ data: CmsMenu }>('/api/cms/menus', { name });
}

export function updateMenu(id: number, payload: { name?: string; slug?: string }) {
    return http.put<{ data: CmsMenu }>(`/api/cms/menus/${id}`, payload);
}

export function deleteMenu(id: number) {
    return http.delete(`/api/cms/menus/${id}`);
}

/** The whole arrangement in one call — dragging one item moves several rows. */
export function saveMenuItems(id: number, items: CmsMenuItemPayload[]) {
    return http.put<{ data: CmsMenu }>(`/api/cms/menus/${id}/items`, { items });
}

/** Pages, posts or categories an item can point at, searched server-side. */
export function menuTargets(type: string, search?: string) {
    return http.get<{ data: CmsMenuTarget[] }>('/api/cms/menu-targets', { params: { type, search } });
}
