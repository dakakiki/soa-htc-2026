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
        /**
         * Render straight into the shell, without its centred container — for a
         * page that paints its own edge-to-edge sections (the front page).
         */
        fullBleed?: boolean;
    }
}

const routes: RouteRecordRaw[] = [
    {
        path: '/',
        name: 'home',
        component: () => import('@/pages/HomePage.vue'),
        meta: { zone: 'public', fullBleed: true },
    },
    {
        path: '/login',
        name: 'login',
        component: () => import('@/pages/LoginPage.vue'),
        meta: { guestOnly: true, zone: 'public' },
    },
    {
        path: '/news',
        name: 'news',
        component: () => import('@/pages/public/NewsListPage.vue'),
        meta: { zone: 'public' },
    },
    {
        path: '/news/:slug',
        name: 'news.post',
        component: () => import('@/pages/public/NewsPostPage.vue'),
        meta: { zone: 'public' },
    },
    {
        path: '/student/access/:mode(sample|competition)',
        name: 'student.access.form',
        component: () => import('@/pages/student/StudentAccessFormPage.vue'),
        meta: { zone: 'public', studentGuestOnly: true },
    },
    {
        path: '/student',
        name: 'student.dashboard',
        component: () => import('@/pages/student/StudentDashboardPage.vue'),
        meta: { zone: 'student' },
    },
    {
        path: '/student/tests/:testId',
        name: 'student.test',
        component: () => import('@/pages/student/StudentTestPage.vue'),
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
        // `schools.view` is data access (venue pickers on the students screen);
        // the Venues screen itself starts at edit rights, as in the legacy app.
        meta: { requiresAuth: true, permission: 'schools.edit' },
    },
    {
        path: '/students',
        name: 'registrations',
        component: () => import('@/pages/students/RegistrationsListPage.vue'),
        meta: { requiresAuth: true, permission: 'students.view' },
    },
    {
        path: '/students/new',
        name: 'registrations.new',
        component: () => import('@/pages/students/RegistrationFormPage.vue'),
        meta: { requiresAuth: true, permission: 'students.view' },
    },
    {
        path: '/students/:id/edit',
        name: 'registrations.edit',
        component: () => import('@/pages/students/RegistrationFormPage.vue'),
        meta: { requiresAuth: true, permission: 'students.view' },
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
        meta: { requiresAuth: true, permission: 'schools.edit' },
    },
    {
        path: '/venues/:id/edit',
        name: 'venues.edit',
        component: () => import('@/pages/venues/VenueFormPage.vue'),
        meta: { requiresAuth: true, permission: 'schools.edit' },
    },
    {
        path: '/profile',
        name: 'profile',
        component: () => import('@/pages/profile/ProfileFormPage.vue'),
        meta: { requiresAuth: true },
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
        meta: { requiresAuth: true, permission: 'coordinators.manage' },
    },
    {
        path: '/coordinators/new',
        name: 'coordinators.new',
        component: () => import('@/pages/coordinators/CoordinatorFormPage.vue'),
        meta: { requiresAuth: true, permission: 'coordinators.manage' },
    },
    {
        path: '/coordinators/:id/edit',
        name: 'coordinators.edit',
        component: () => import('@/pages/coordinators/CoordinatorFormPage.vue'),
        meta: { requiresAuth: true, permission: 'coordinators.manage' },
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
        path: '/settings/certificate',
        name: 'settings.certificate',
        component: () => import('@/pages/settings/CertificateSettingsPage.vue'),
        meta: { requiresAuth: true, permission: 'settings.manage' },
    },
    {
        path: '/settings/season',
        name: 'settings.season',
        component: () => import('@/pages/settings/SeasonSettingsPage.vue'),
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
        path: '/grading',
        name: 'grading',
        component: () => import('@/pages/grading/GradingListPage.vue'),
        meta: { requiresAuth: true, permission: 'results.manage' },
    },
    {
        path: '/grading/:id',
        name: 'grading.attempt',
        component: () => import('@/pages/grading/GradingPage.vue'),
        meta: { requiresAuth: true, permission: 'results.manage' },
    },
    {
        path: '/publishing',
        name: 'publishing',
        component: () => import('@/pages/results/PublishingPage.vue'),
        meta: { requiresAuth: true, permission: 'results.manage' },
    },
    {
        path: '/results/import',
        name: 'results.import',
        component: () => import('@/pages/results/ImportPage.vue'),
        meta: { requiresAuth: true, permission: 'results.manage' },
    },
    {
        path: '/results/export',
        name: 'results.export',
        component: () => import('@/pages/results/ExportPage.vue'),
        meta: { requiresAuth: true, permission: 'results.manage' },
    },
    {
        path: '/reports',
        name: 'reports',
        component: () => import('@/pages/results/ReportsPage.vue'),
        meta: { requiresAuth: true, permission: 'reports.view' },
    },
    {
        path: '/results/archive',
        name: 'results.archive',
        component: () => import('@/pages/results/ArchivePage.vue'),
        meta: { requiresAuth: true, permission: 'reports.view' },
    },
    {
        path: '/reset',
        name: 'reset',
        component: () => import('@/pages/results/ResetPage.vue'),
        meta: { requiresAuth: true, permission: 'results.manage' },
    },
    {
        path: '/website/pages',
        name: 'cms.pages',
        component: () => import('@/pages/cms/PagesListPage.vue'),
        meta: { requiresAuth: true, permission: 'cms.manage' },
    },
    {
        path: '/website/pages/new',
        name: 'cms.pages.new',
        component: () => import('@/pages/cms/PageFormPage.vue'),
        meta: { requiresAuth: true, permission: 'cms.manage' },
    },
    {
        path: '/website/pages/:id/edit',
        name: 'cms.pages.edit',
        component: () => import('@/pages/cms/PageFormPage.vue'),
        meta: { requiresAuth: true, permission: 'cms.manage' },
    },
    {
        path: '/website/posts',
        name: 'cms.posts',
        component: () => import('@/pages/cms/PostsListPage.vue'),
        meta: { requiresAuth: true, permission: 'cms.manage' },
    },
    {
        path: '/website/posts/new',
        name: 'cms.posts.new',
        component: () => import('@/pages/cms/PostFormPage.vue'),
        meta: { requiresAuth: true, permission: 'cms.manage' },
    },
    {
        path: '/website/posts/:id/edit',
        name: 'cms.posts.edit',
        component: () => import('@/pages/cms/PostFormPage.vue'),
        meta: { requiresAuth: true, permission: 'cms.manage' },
    },
    {
        path: '/website/menus',
        name: 'cms.menus',
        component: () => import('@/pages/cms/MenusListPage.vue'),
        meta: { requiresAuth: true, permission: 'cms.manage' },
    },
    {
        path: '/website/menus/:id',
        name: 'cms.menus.edit',
        component: () => import('@/pages/cms/MenuEditPage.vue'),
        meta: { requiresAuth: true, permission: 'cms.manage' },
    },
    {
        path: '/cms/layout',
        name: 'cms.layout',
        component: () => import('@/pages/cms/LayoutPage.vue'),
        meta: { requiresAuth: true, permission: 'cms.manage' },
    },
    {
        path: '/website/media',
        name: 'cms.media',
        component: () => import('@/pages/cms/MediaListPage.vue'),
        meta: { requiresAuth: true, permission: 'cms.manage' },
    },
    {
        path: '/website/categories',
        name: 'cms.categories',
        component: () => import('@/pages/cms/CategoriesListPage.vue'),
        meta: { requiresAuth: true, permission: 'cms.manage' },
    },
    /*
     * A CMS page lives at the root (`/about`). It is declared last so every
     * application route wins the match first; the component itself falls back to
     * the not-found screen when the slug is not a published page.
     */
    {
        path: '/:slug',
        name: 'cms.page',
        component: () => import('@/pages/public/CmsPageView.vue'),
        meta: { zone: 'public' },
    },
    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import('@/pages/NotFoundPage.vue'),
        meta: { zone: 'public' },
    },
];

/** Clears the sticky public header when an address points at a section. */
const HEADER_OFFSET = 80;

export const router = createRouter({
    history: createWebHistory(),
    routes,
    /**
     * Hash addresses are real navigation here: the header menu carries
     * `/#block_Start` and friends over from the live site, so those have to land
     * on the section rather than at the top of the page. The wait for a frame
     * lets the target page paint before we measure it.
     */
    scrollBehavior(to, _from, saved) {
        if (to.hash) {
            return new Promise((resolve) => {
                requestAnimationFrame(() =>
                    resolve({ el: to.hash, top: HEADER_OFFSET, behavior: 'smooth' }),
                );
            });
        }

        return saved ?? { top: 0 };
    },
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
            return { name: 'home' };
        }
    }

    return true;
});
