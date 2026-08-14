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
    regions_count?: number;
    schools_count?: number;
}

export type ThemeColorKey =
    | 'primary'
    | 'primary_hover'
    | 'primary_soft'
    | 'on_primary'
    | 'accent'
    | 'accent_hover'
    | 'link'
    | 'border';

export interface Theme {
    logo_url: string | null;
    logo_icon_url: string | null;
    colors: Record<ThemeColorKey, string>;
}

export interface School {
    id: number;
    name: string;
    status: string;
    country: { id: number; name?: string };
    region?: { id: number; name?: string | null };
    city?: string | null;
    address?: string | null;
    phone?: string | null;
    email?: string | null;
    hours_eng_per_week?: number | null;
    invigilators_count?: number | null;
    school_type?: string | null;
    image_url?: string | null;
}

export interface Region {
    id: number;
    name: string;
    country_id: number;
    schools_count?: number;
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
    status: string;
    city: string | null;
    address: string | null;
    phone: string | null;
    image_url: string | null;
    file_url: string | null;
    can_student_insert: boolean;
    can_student_edit: boolean;
    can_student_delete: boolean;
    can_reset_test_results: boolean;
    country: { id: number | null; name?: string };
    region?: { id: number | null; name?: string | null };
    roles: string[];
    assignments: Assignment[];
}

export interface CoordinatorSchool {
    id: number;
    name: string;
    city: string | null;
    country: string | null;
    status: string;
}

export interface Coordinator {
    id: number;
    name: string;
    email: string;
    status: string;
    city: string | null;
    address: string | null;
    phone: string | null;
    image_url: string | null;
    file_url: string | null;
    can_student_insert: boolean;
    can_student_edit: boolean;
    can_student_delete: boolean;
    can_reset_test_results: boolean;
    country: { id: number | null; name?: string };
    region?: { id: number | null; name?: string | null };
    assignment_id: number | null;
    role: { id: number; key: string; name: string } | null;
    venues_count: number;
    schools: CoordinatorSchool[];
}

export interface DashboardData {
    season: { name: string; round_number: number; status: string; ends_at: string | null } | null;
    venues: { count: number; scoped: boolean };
    users: { count: number } | null;
    coordinators: { count: number } | null;
}

export interface Paginated<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
}
