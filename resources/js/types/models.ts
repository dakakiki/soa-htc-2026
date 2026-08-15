export interface AuthUser {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    can_student_insert: boolean;
    can_student_edit: boolean;
    can_student_delete: boolean;
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
    level_counts?: Record<string, number>;
    total_competitors?: number;
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

export interface DifficultyLevel {
    id: number;
    difficulty_category_id: number;
    name: string;
    level_short: string;
    grades: number[];
    position: number;
    status: string;
}

export interface DifficultyCategory {
    id: number;
    name: string;
    type: string;
    type_label: string;
    countries_all: boolean;
    countries?: { id: number; code: string; name: string }[];
    levels_count?: number;
    status: string;
}

export interface LevelOption {
    id: number;
    level_short: string;
    name: string;
    grades: number[];
    category_name: string;
    category_type: string;
}

export interface QuestionAnswer {
    id?: number;
    text: string;
    is_correct: boolean;
    position: number;
}

export interface Question {
    id: number;
    title: string;
    description: string | null;
    question_type: string;
    question_type_label: string;
    points: number;
    status: string;
    tag?: { id: number; name?: string };
    image_url: string | null;
    audio_url: string | null;
    levels?: { id: number; level_short: string }[];
    answers?: QuestionAnswer[];
    answers_count?: number;
}

export interface TestQuestionRef {
    id: number;
    title: string;
    points: number;
    position: number;
}

export interface Test {
    id: number;
    title: string;
    description: string | null;
    duration: number | null;
    status: string;
    type?: { id: number; name?: string };
    levels?: { id: number; level_short: string }[];
    questions?: TestQuestionRef[];
    questions_count?: number;
}

export interface TestPreviewAnswer {
    text: string;
    is_correct: boolean;
    position: number;
}

export interface TestPreviewQuestion {
    id: number;
    title: string;
    description: string | null;
    question_type: string;
    question_type_label: string;
    points: number;
    position: number;
    answers: TestPreviewAnswer[];
}

export interface TestPreview {
    id: number;
    title: string;
    description: string | null;
    duration: number | null;
    type?: { id: number; name?: string };
    questions: TestPreviewQuestion[];
}

export interface ExamTestRef {
    id: number;
    title: string;
    position: number;
}

export interface Exam {
    id: number;
    title: string;
    description: string | null;
    status: string;
    round?: { id: number; name?: string };
    levels?: { id: number; level_short: string }[];
    tests?: ExamTestRef[];
    tests_count?: number;
}

export interface QuizExamRef {
    id: number;
    title: string;
    position: number;
}

export interface Quiz {
    id: number;
    title: string;
    description: string | null;
    quiz_type: string;
    quiz_type_label: string;
    status: string;
    has_password: boolean;
    levels?: { id: number; level_short: string }[];
    exams?: QuizExamRef[];
    exams_count?: number;
}

export interface Registration {
    id: number;
    competitor_number: string;
    name: string;
    date_of_birth: string | null;
    grade: number | null;
    status: string;
    school_external: string | null;
    school?: { id: number; name?: string };
    country?: { id: number; name?: string };
    level?: { id: number; level_short?: string };
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
    level_counts?: Record<string, number>;
    total_competitors?: number;
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
