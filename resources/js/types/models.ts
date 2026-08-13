export interface AuthUser {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    roles: string[];
    permissions: string[];
}

export interface Country {
    id: number;
    code: string;
    name: string;
}

export interface School {
    id: number;
    name: string;
    status: string;
    country: { id: number; name?: string };
    region?: { id: number; name?: string | null };
}

export interface Region {
    id: number;
    name: string;
    country_id: number;
}

export interface Role {
    id: number;
    key: string;
    name: string;
    is_system?: boolean;
    permissions?: string[];
}

export interface Permission {
    key: string;
    description: string | null;
}

export interface Assignment {
    id: number;
    status: string;
    season: { id: number; name?: string };
    role: { id: number; key?: string; name?: string };
    schools: { id: number; name: string }[];
}

export interface AdminUser {
    id: number;
    name: string;
    email: string;
    country: { id: number | null; name?: string };
    region?: { id: number | null; name?: string | null };
    roles: string[];
    assignments: Assignment[];
}

export interface Paginated<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
}
