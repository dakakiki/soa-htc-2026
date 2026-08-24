/** Row-level scope of the signed-in account (see UserResource::scope). */
export interface AuthScope {
    /** True for an admin: nothing is pinned and every venue is in reach. */
    all_schools: boolean;
    country: { id: number; name: string | null } | null;
    schools: { id: number; name: string; region: { id: number; name: string | null } | null }[];
}

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
    scope: AuthScope;
}

export interface Country {
    id: number;
    code: string;
    name: string;
    regions_count?: number;
    schools_count?: number;
}

/** The student-safe view of a registration returned after web identification. */
export interface StudentRegistrationSummary {
    competitor_number: string;
    name: string;
    grade: number | null;
    level: string | null;
    venue: string | null;
    country: string | null;
}

export interface StudentIdentifyResult {
    token: string;
    expires_at: string;
    registration: StudentRegistrationSummary;
}

export type TestStatus = 'locked' | 'next' | 'in_progress' | 'completed';

export interface AvailabilityTest {
    id: number;
    title: string;
    type: string | null;
    duration: number | null;
    status: TestStatus;
    published: boolean;
    score: number | null;
    max_score: number | null;
}

export interface AvailabilityExam {
    id: number;
    title: string;
    round: string | null;
    tests: AvailabilityTest[];
}

export interface AvailabilityQuiz {
    id: number;
    title: string;
    mode: 'sample' | 'competition';
    requires_password: boolean;
    unlocked: boolean;
    exams: AvailabilityExam[];
}

export type QuestionType = 'multiple_choice' | 'gap_filling' | 'essay';

export interface AttemptQuestionOption {
    id: number;
    text: string;
}

export interface AttemptQuestion {
    id: number;
    title: string | null;
    description: string | null;
    question_type: QuestionType;
    answer_numbering: string | null;
    points: number;
    position: number;
    image_url: string | null;
    audio_url: string | null;
    options: AttemptQuestionOption[];
}

export interface AttemptSummary {
    id: number;
    status: 'in_progress' | 'completed';
    expires_at?: string;
    remaining_seconds?: number;
    submitted_at?: string | null;
}

export interface AttemptSession {
    attempt: AttemptSummary;
    test: { id: number; title: string; description: string | null; duration: number | null };
    questions: AttemptQuestion[];
}

/** A submitted answer's response payload, shaped by the question type. */
export type AnswerResponse =
    | { selected: number[] }
    | { gaps: string[] }
    | { text: string };

export interface SubmitAnswer {
    question_id: number;
    response: AnswerResponse;
}

export type ThemeColorKey =
    | 'primary'
    | 'primary_hover'
    | 'primary_soft'
    | 'on_primary'
    | 'accent'
    | 'accent_hover'
    | 'link'
    | 'border'
    // Free palette slots (no fixed role) — shared with the public/CMS side.
    | 'palette_1'
    | 'palette_2'
    | 'palette_3'
    | 'palette_4';

export interface Theme {
    /** Rich-text site name (admin-authored HTML), rendered next to the logo. */
    site_title: string | null;
    logo_url: string | null;
    logo_dark_url: string | null;
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
    /** Display order within the country, set by drag & drop in the locations admin. */
    position?: number;
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
    // Rich text, and optional: a question's number comes from its position in the
    // test, so most carry no heading of their own.
    title: string | null;
    description: string | null;
    question_type: string;
    question_type_label: string;
    // How the options are labelled: 'lower_alpha' | 'upper_alpha' | 'numeric', or
    // null for a plain list. The marker is rendered, never stored in the text.
    answer_numbering: string | null;
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
    title: string | null;
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
    attendance: string;
    school_external: string | null;
    school?: { id: number; name?: string };
    country?: { id: number; name?: string };
    level?: { id: number; level_short?: string };
    results?: Record<string, RegistrationResultCell>;
}

/** One test-type column within a round (first letter is the column head). */
export interface ResultTypeCol {
    id: number;
    name: string;
    letter: string;
}

/** A round in the results grid: its test-type columns (empty for future rounds). */
export interface ResultColumn {
    round_id: number;
    round: string;
    short: string;
    types: ResultTypeCol[];
}

/**
 * A competitor's results for one round: per test-type scores + total (for rounds
 * with tests), and/or an advancement code S/Q/F (fills the test-less RQ/WF columns).
 */
export interface RegistrationResultCell {
    types?: Record<string, number>;
    sum?: number;
    qual?: string;
}

/** One round's detailed breakdown for the results modal. */
export interface ResultDetailRound {
    round_id: number;
    round: string;
    qual?: string;
    tests: { test: string; type: string; score: number | null; max_score: number | null }[];
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

/** One country on the dashboard map and in the country table. */
export interface CountryMapRow {
    iso: number;
    /** The country row behind it — the table links with this. */
    id: number;
    name: string;
    students: number;
    venues: number;
    submitted: number;
    published: number;
}

export interface VenueDashboardRow {
    id: number;
    name: string;
    city: string | null;
    students: number;
    absent: number;
    submitted: number;
}

export interface StudentPreviewRow {
    id: number;
    competitor_number: string;
    name: string;
    grade: number | null;
    level: string | null;
    attendance: string;
    score: number | null;
    max_score: number | null;
}

export interface DashboardData {
    season: { name: string; round_number: number; status: string; ends_at: string | null } | null;
    venues: { count: number; scoped: boolean };
    users: { count: number } | null;
    coordinators: { count: number } | null;
    /** Present only for accounts that see beyond one country (the map audience). */
    by_country: CountryMapRow[] | null;
    kpis: {
        students: number;
        submitted: number;
        present: number;
        absent: number;
        /** Null for a scoped account: one country is not a statistic. */
        countries: number | null;
        venues_active: number | null;
        students_previous_round: number | null;
    };
    /** Only non-empty items, so an empty list means nothing is pending. */
    attention: { key: string; count: number }[];
    /** The coordinator's venues; null for an admin and for a single-venue scope. */
    by_venue: VenueDashboardRow[] | null;
    /** The first page of the roster, for a scope of exactly one venue. */
    students_preview: StudentPreviewRow[] | null;
    /** Roster size per round, archive plus the season under way. */
    trend: { round: number; students: number; current: boolean }[] | null;
}

/**
 * Global search hits, grouped by what they are. A group is absent when the
 * account may not see it and when it simply has no matches — the SPA renders
 * whatever arrives, in a fixed order.
 */
export interface SearchResults {
    students?: { id: number; competitor_number: string; name: string; venue: string | null; country: string | null }[];
    venues?: { id: number; name: string; city: string | null; country: string | null }[];
    countries?: { id: number; name: string; code: string; students: number }[];
    users?: { id: number; name: string; email: string; country: string | null }[];
    coordinators?: { id: number; name: string; email: string; country: string | null }[];
}

/* ------------------------------------------------------------------ website */

export interface CmsCategory {
    id: number;
    parent_id: number | null;
    parent?: { id: number; name: string } | null;
    name: string;
    slug: string;
    description: string | null;
    status: string;
    position: number;
    locale: string;
    posts_count?: number;
}

export interface CmsMedia {
    id: number;
    url: string;
    original_name: string;
    mime_type: string;
    size: number;
    width: number | null;
    height: number | null;
    alt: string | null;
    uploaded_by?: string | null;
    created_at: string | null;
}

export interface CmsPost {
    id: number;
    title: string;
    slug: string;
    /** The address this post has on the public site. */
    path: string;
    excerpt: string | null;
    body: string | null;
    image_media_id: number | null;
    image_url: string | null;
    author?: { id: number; name: string } | null;
    status: string;
    published_at: string | null;
    seo_title: string | null;
    seo_description: string | null;
    locale: string;
    categories?: { id: number; name: string; slug: string }[];
}

export interface CmsPage {
    id: number;
    title: string;
    slug: string;
    path: string;
    body: string | null;
    image_media_id: number | null;
    image_url: string | null;
    status: string;
    published_at: string | null;
    seo_title: string | null;
    seo_description: string | null;
    locale: string;
}

export type CmsMenuItemType = 'page' | 'post' | 'category' | 'custom';

export interface CmsMenuItem {
    id: number;
    type: CmsMenuItemType;
    page_id: number | null;
    post_id: number | null;
    category_id: number | null;
    url: string | null;
    /** The per-item override; null means "use the target's own name". */
    label: string | null;
    /** What the target is called, so the editor can show what a cleared label falls back to. */
    target_name: string | null;
    resolved_label: string;
    href: string | null;
    link_target: string;
    children: CmsMenuItem[];
}

export interface CmsMenu {
    id: number;
    name: string;
    slug: string;
    items_count?: number;
    items?: CmsMenuItem[];
}

/** One item as it is sent back to the server. */
export interface CmsMenuItemPayload {
    type: CmsMenuItemType;
    page_id?: number | null;
    post_id?: number | null;
    category_id?: number | null;
    url?: string | null;
    label?: string | null;
    link_target?: string;
    children?: CmsMenuItemPayload[];
}

export interface CmsMenuTarget {
    id: number;
    label: string;
    slug: string;
}

/** What the public site reads — no draft fields, no author account. */
export interface PublicPost {
    title: string;
    slug: string;
    path: string;
    excerpt: string | null;
    body: string | null;
    image_url: string | null;
    author?: string | null;
    published_at: string | null;
    seo_title: string | null;
    seo_description: string | null;
    categories?: { name: string; slug: string }[];
}

export interface PublicPage {
    title: string;
    slug: string;
    path: string;
    body: string | null;
    image_url: string | null;
    seo_title: string | null;
    seo_description: string | null;
    published_at: string | null;
}

/** A navigation as the site renders it: no ids, no drafts, nothing to resolve. */
export interface PublicMenuItem {
    label: string;
    href: string | null;
    target: string;
    children: PublicMenuItem[];
}

export interface PublicMenu {
    name: string;
    slug: string;
    items: PublicMenuItem[];
}

export interface PublicCategory {
    id: number;
    name: string;
    slug: string;
    posts_count: number;
}

/**
 * A layout button the server has already decided is visible (ADR-0043): both the
 * admin's switch and the season gate passed, and it has somewhere to go.
 */
export interface PublicBlockButton {
    label: string;
    href: string;
    style: 'primary' | 'navy' | 'amber' | 'outline' | 'link';
    download: boolean;
    external: boolean;
}

/** One section of a zone. `content` is shaped by the block's type. */
export interface PublicBlock {
    type: string;
    content: Record<string, unknown>;
    image: { url: string; alt: string | null } | null;
}

/**
 * One editable field of a block type. The server declares these (BlockSchema),
 * so the form and the validation cannot drift apart.
 */
export interface LayoutField {
    key: string;
    kind: 'text' | 'textarea' | 'rich' | 'number' | 'enum' | 'list' | 'button' | 'buttons';
    label: string;
    max?: number;
    min?: number;
    options?: string[];
    item?: LayoutField[];
}

export interface LayoutTypeInfo {
    key: string;
    label: string;
    /** How many of this type a zone may hold; null means no limit. */
    max: number | null;
    uses_image: boolean;
    fields: LayoutField[];
}

export interface LayoutZoneInfo {
    key: string;
    label: string;
    description: string;
    types: LayoutTypeInfo[];
}

export interface LayoutRegistry {
    zones: LayoutZoneInfo[];
    button_styles: string[];
    target_types: string[];
    gates: string[];
}

/** A button as the editor holds it, before the server decides who sees it. */
export interface LayoutButtonValue {
    label: string;
    style: string;
    status: boolean;
    gate: string | null;
    target: { type: string; id: number | null; value: string | null };
}

export interface CmsLayoutBlock {
    id: number;
    zone: string;
    type: string;
    type_label: string;
    position: number;
    status: boolean;
    content: Record<string, unknown>;
    image?: CmsMedia | null;
    image_media_id: number | null;
}

/** Which round is running, and whether it can be entered. */
export interface SiteStatus {
    round: number | null;
    year: number | null;
    season: string | null;
    competition_open: boolean;
    sample_open: boolean;
}

export interface Paginated<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
}
