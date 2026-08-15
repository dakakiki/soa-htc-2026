import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { useSessionStore } from '@/stores/session';
import { useStudentSessionStore } from '@/stores/studentSession';

/**
 * Which application shell a route renders in (ADR-0014). `App.vue` maps this to
 * a layout component. Routes without a zone default to `admin` (fail-safe).
 */
export type Zone = 'public' | 'admin' | 'student';

declare module 'vue-router' {
    interface RouteMeta {
        requiresAuth?: boolean;
        guestOnly?: boolean;
        permission?: string;
        zone?: Zone;
        /** Redirect an already-identified competitor away (e.g. the access form). */
        studentGuestOnly?: boolean;
    }
}

const routes: RouteRecordRaw[] = [
    {
        path: '/',
        name: 'home',
        component: () => import('@/pages/HomePage.vue'),
        meta: { zone: 'public' },
    },
    {
        path: '/login',
        name: 'login',
        component: () => import('@/pages/LoginPage.vue'),
        meta: { guestOnly: true, zone: 'public' },
    },
    {
        path: '/student/access',
        name: 'student.access',
        component: () => import('@/pages/student/StudentAccessPage.vue'),
        meta: { zone: 'public', studentGuestOnly: true },
    },
    {
        path: '/student',
        name: 'student.dashboard',
        component: () => import('@/pages/student/StudentDashboardPage.vue'),
        meta: { zone: 'student' },
    },
    {
        path: '/dashboard',
        name: 'dashboard',
        component: () => import('@/pages/DashboardPage.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/venues',
        name: 'venues',
        component: () => import('@/pages/venues/VenuesListPage.vue'),
        meta: { requiresAuth: true, permission: 'schools.view' },
    },
    {
        path: '/students',
        name: 'registrations',
        component: () => import('@/pages/students/RegistrationsListPage.vue'),
        meta: { requiresAuth: true, permission: 'schools.view' },
    },
    {
        path: '/students/new',
        name: 'registrations.new',
        component: () => import('@/pages/students/RegistrationFormPage.vue'),
        meta: { requiresAuth: true, permission: 'schools.view' },
    },
    {
        path: '/students/:id/edit',
        name: 'registrations.edit',
        component: () => import('@/pages/students/RegistrationFormPage.vue'),
        meta: { requiresAuth: true, permission: 'schools.view' },
    },
    {
        path: '/venues/new',
        name: 'venues.new',
        component: () => import('@/pages/venues/VenueFormPage.vue'),
        meta: { requiresAuth: true, permission: 'schools.manage' },
    },
    {
        path: '/venues/:id',
        name: 'venues.view',
        component: () => import('@/pages/venues/VenueViewPage.vue'),
        meta: { requiresAuth: true, permission: 'schools.view' },
    },
    {
        path: '/venues/:id/edit',
        name: 'venues.edit',
        component: () => import('@/pages/venues/VenueFormPage.vue'),
        meta: { requiresAuth: true, permission: 'schools.manage' },
    },
    {
        path: '/users',
        name: 'users',
        component: () => import('@/pages/users/UsersListPage.vue'),
        meta: { requiresAuth: true, permission: 'users.manage' },
    },
    {
        path: '/users/new',
        name: 'users.new',
        component: () => import('@/pages/users/UserFormPage.vue'),
        meta: { requiresAuth: true, permission: 'users.manage' },
    },
    {
        path: '/users/:id/edit',
        name: 'users.edit',
        component: () => import('@/pages/users/UserFormPage.vue'),
        meta: { requiresAuth: true, permission: 'users.manage' },
    },
    {
        path: '/coordinators',
        name: 'coordinators',
        component: () => import('@/pages/coordinators/CoordinatorsListPage.vue'),
        meta: { requiresAuth: true, permission: 'users.manage' },
    },
    {
        path: '/coordinators/new',
        name: 'coordinators.new',
        component: () => import('@/pages/coordinators/CoordinatorFormPage.vue'),
        meta: { requiresAuth: true, permission: 'users.manage' },
    },
    {
        path: '/coordinators/:id/edit',
        name: 'coordinators.edit',
        component: () => import('@/pages/coordinators/CoordinatorFormPage.vue'),
        meta: { requiresAuth: true, permission: 'users.manage' },
    },
    {
        path: '/locations',
        name: 'locations',
        component: () => import('@/pages/locations/LocationsListPage.vue'),
        meta: { requiresAuth: true, permission: 'locations.manage' },
    },
    {
        path: '/difficulty',
        name: 'difficulty',
        component: () => import('@/pages/difficulty/DifficultyListPage.vue'),
        meta: { requiresAuth: true, permission: 'difficulty.manage' },
    },
    {
        path: '/content/questions',
        name: 'questions',
        component: () => import('@/pages/content/QuestionsListPage.vue'),
        meta: { requiresAuth: true, permission: 'content.manage' },
    },
    {
        path: '/content/questions/new',
        name: 'questions.new',
        component: () => import('@/pages/content/QuestionFormPage.vue'),
        meta: { requiresAuth: true, permission: 'content.manage' },
    },
    {
        path: '/content/questions/:id/edit',
        name: 'questions.edit',
        component: () => import('@/pages/content/QuestionFormPage.vue'),
        meta: { requiresAuth: true, permission: 'content.manage' },
    },
    {
        path: '/content/quizzes',
        name: 'quizzes',
        component: () => import('@/pages/content/QuizzesListPage.vue'),
        meta: { requiresAuth: true, permission: 'content.manage' },
    },
    {
        path: '/content/quizzes/new',
        name: 'quizzes.new',
        component: () => import('@/pages/content/QuizFormPage.vue'),
        meta: { requiresAuth: true, permission: 'content.manage' },
    },
    {
        path: '/content/quizzes/:id/edit',
        name: 'quizzes.edit',
        component: () => import('@/pages/content/QuizFormPage.vue'),
        meta: { requiresAuth: true, permission: 'content.manage' },
    },
    {
        path: '/content/exams',
        name: 'exams',
        component: () => import('@/pages/content/ExamsListPage.vue'),
        meta: { requiresAuth: true, permission: 'content.manage' },
    },
    {
        path: '/content/exams/new',
        name: 'exams.new',
        component: () => import('@/pages/content/ExamFormPage.vue'),
        meta: { requiresAuth: true, permission: 'content.manage' },
    },
    {
        path: '/content/exams/:id/edit',
        name: 'exams.edit',
        component: () => import('@/pages/content/ExamFormPage.vue'),
        meta: { requiresAuth: true, permission: 'content.manage' },
    },
    {
        path: '/content/tests',
        name: 'tests',
        component: () => import('@/pages/content/TestsListPage.vue'),
        meta: { requiresAuth: true, permission: 'content.manage' },
    },
    {
        path: '/content/tests/new',
        name: 'tests.new',
        component: () => import('@/pages/content/TestFormPage.vue'),
        meta: { requiresAuth: true, permission: 'content.manage' },
    },
    {
        path: '/content/tests/:id/edit',
        name: 'tests.edit',
        component: () => import('@/pages/content/TestFormPage.vue'),
        meta: { requiresAuth: true, permission: 'content.manage' },
    },
    {
        path: '/content/test-types',
        name: 'content.test-types',
        component: () => import('@/pages/content/LookupListPage.vue'),
        props: { kind: 'testType' },
        meta: { requiresAuth: true, permission: 'content.manage' },
    },
    {
        path: '/content/exam-rounds',
        name: 'content.exam-rounds',
        component: () => import('@/pages/content/LookupListPage.vue'),
        props: { kind: 'examRound' },
        meta: { requiresAuth: true, permission: 'content.manage' },
    },
    {
        path: '/content/tags',
        name: 'content.tags',
        component: () => import('@/pages/content/LookupListPage.vue'),
        props: { kind: 'tag' },
        meta: { requiresAuth: true, permission: 'content.manage' },
    },
    {
        path: '/settings/theme',
        name: 'settings.theme',
        component: () => import('@/pages/settings/ThemeSettingsPage.vue'),
        meta: { requiresAuth: true, permission: 'settings.manage' },
    },
    {
        path: '/roles',
        name: 'roles',
        component: () => import('@/pages/roles/RolesListPage.vue'),
        meta: { requiresAuth: true, permission: 'roles.manage' },
    },
    {
        path: '/roles/new',
        name: 'roles.new',
        component: () => import('@/pages/roles/RoleFormPage.vue'),
        meta: { requiresAuth: true, permission: 'roles.manage' },
    },
    {
        path: '/roles/:id',
        name: 'roles.view',
        component: () => import('@/pages/roles/RoleViewPage.vue'),
        meta: { requiresAuth: true, permission: 'roles.manage' },
    },
    {
        path: '/roles/:id/edit',
        name: 'roles.edit',
        component: () => import('@/pages/roles/RoleFormPage.vue'),
        meta: { requiresAuth: true, permission: 'roles.manage' },
    },
    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import('@/pages/NotFoundPage.vue'),
        meta: { zone: 'public' },
    },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const session = useSessionStore();
    await session.ensureLoaded();

    if (to.meta.guestOnly && session.isAuthenticated) {
        return { name: 'dashboard' };
    }

    if (to.meta.requiresAuth && !session.isAuthenticated) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    if (to.meta.permission && !session.can(to.meta.permission)) {
        return { name: 'home' };
    }

    // Competitor (student) session — separate from the admin session above.
    if (to.meta.zone === 'student' || to.meta.studentGuestOnly) {
        const student = useStudentSessionStore();
        await student.ensureLoaded();

        if (to.meta.studentGuestOnly && student.isIdentified) {
            return { name: 'student.dashboard' };
        }

        if (to.meta.zone === 'student' && !student.isIdentified) {
            return { name: 'student.access' };
        }
    }

    return true;
});
