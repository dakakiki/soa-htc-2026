import { http } from '@/api/http';
import type { AdminUser } from '@/types/models';

/*
 * The signed-in account's own profile. `editable` is the server's list of fields
 * this user's role may change — the form renders from it rather than from a copy
 * of the rules, so the two can never disagree.
 */
export type ProfileField =
    | 'name'
    | 'email'
    | 'password'
    | 'country_id'
    | 'region_id'
    | 'city'
    | 'address'
    | 'phone'
    | 'image'
    | 'file_upload';

export interface ProfileResponse {
    data: AdminUser;
    editable: ProfileField[];
}

export interface ProfilePayload {
    name?: string;
    email?: string;
    password?: string;
    current_password?: string;
    country_id?: number | null;
    region_id?: number | null;
    city?: string | null;
    address?: string | null;
    phone?: string | null;
}

export interface ProfileFiles {
    image?: File | null;
    file_upload?: File | null;
}

export function getProfile() {
    return http.get<ProfileResponse>('/api/profile');
}

/** Multipart with method spoofing, because PUT bodies aren't parsed for uploads. */
export function updateProfile(payload: ProfilePayload, files?: ProfileFiles) {
    const fd = new FormData();
    Object.entries(payload).forEach(([key, value]) => {
        if (value !== undefined && value !== null) {
            fd.append(key, String(value));
        }
    });
    if (files?.image) {
        fd.append('image', files.image);
    }
    if (files?.file_upload) {
        fd.append('file_upload', files.file_upload);
    }
    fd.append('_method', 'PUT');
    return http.post<ProfileResponse>('/api/profile', fd);
}

export function deleteProfileAsset(asset: 'image' | 'file') {
    return http.delete<ProfileResponse>(`/api/profile/assets/${asset}`);
}
