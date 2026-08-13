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

export interface Paginated<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
}
