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
        /** One permission, or a list of which ALL are required. */
        permission?: string | string[];
        zone?: Zone;
        /**
         * Render straight into the shell, without its centred container — for a
         * page that paints its own edge-to-edge sections (the front page).
         */
        fullBleed?: boolean;
        /**
         * The screen replaces the shell's chrome with its own: no header, no
         * container, nothing around it. A test in progress is the whole window
         * — a sign-out button beside a running clock is an invitation to lose
         * an exam by mis-tapping.
         */
        bare?: boolean;
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
        /*
         * Coordinator registration (ADR-0053). `guestOnly` like the sign-in
         * screen: somebody already signed in has an account, and the form would
         * only be a way of making a second one.
         */
        path: '/register',
        name: 'register',
        component: () => import('@/pages/RegisterPage.vue'),
        meta: { guestOnly: true, zone: 'public' },
    },
    {
        /*
         * Password recovery (ADR-0063), two screens. `guestOnly` like the two
         * above: somebody already signed in changes their password on their own
         * profile screen, where knowing the old one is the proof of identity.
         *
         * 🪤 The token is a path parameter and the address a query one, which is
         * the shape the mail writes and the shape Laravel's own reset route
         * uses. Both halves are the broker's and are handed straight back to it.
         */
        path: '/forgot-password',
        name: 'password.forgot',
        component: () => import('@/pages/ForgotPasswordPage.vue'),
        meta: { guestOnly: true, zone: 'public' },
    },
    {
        /*
         * 🪤 NOT `guestOnly`, unlike every other screen around it. This one is
         * arrived at from an e-mail, and a browser that still holds a live
         * session would be bounced to the dashboard — the link would look broken
         * to the one person holding a valid one. The token is proof of the
         * mailbox and stands on its own; the screen signs the session out itself
         * once the password has changed, because the server has just deleted it.
         */
        path: '/reset-password/:token',
        name: 'password.reset',
        component: () => import('@/pages/ResetPasswordPage.vue'),
        meta: { zone: 'public' },
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
        /*
         * All three streams ask for the three details every time, even when a
         * session is already open (owner, 2026-08-27). The form used to be
         * skipped for anyone already identified, which sent a competitor who
         * had clicked "Check results" to the dashboard instead of to their
         * marks.
         */
        path: '/student/access/:mode(sample|competition|results)',
        name: 'student.access.form',
        component: () => import('@/pages/student/StudentAccessFormPage.vue'),
        meta: { zone: 'public' },
    },
    {
        /*
         * Looking up your own results (owner, 2026-08-27). Reached by the same
         * identification as the exam streams and with no exam password, because
         * nothing here opens an exam — it only reads what has already been sat.
         */
        path: '/student/results',
        name: 'student.results',
        component: () => import('@/pages/student/StudentResultsPage.vue'),
        meta: { zone: 'student' },
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
        meta: { zone: 'student', bare: true },
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
        // The public registration queue (ADR-0053). Its own permission: managing
        // the coordinators a country already has is routine, letting a stranger
        // in is not.
        // 🪤 The name deliberately does NOT start with `coordinators.` — the
        // sidebar lights an entry when the route name starts with its prefix, so
        // `coordinators.registrations` would light the Coordinators link as well
        // as this one.
        path: '/coordinator-registrations',
        name: 'registrationQueue',
        component: () => import('@/pages/coordinators/RegistrationQueuePage.vue'),
        meta: { requiresAuth: true, permission: 'coordinators.approve' },
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
        // Both, matching what the server enforces: the archive is denormalized to
        // names and cannot be narrowed to a reader's venues, so it is refused to
        // anyone who is not global (ADR-0067).
        meta: { requiresAuth: true, permission: ['reports.view', 'schools.view.all'] },
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

/**
 * How far below the top of the window a section lands when an address points at
 * it — enough that its rule and its heading are both fully in view, rather than
 * clipped at the very edge.
 *
 * 🪤 POSITIVE. Vue Router computes `elRect.top - docRect.top - offset.top`, so
 * it SUBTRACTS what you give it: the element ends up exactly `offset` px below
 * the top of the window. A negative value — which is what "clear the header"
 * intuition suggests — pushes the section that far ABOVE the fold and cuts its
 * heading off (owner, 2026-08-27). Matches the `scroll-mt-20` the sections
 * already carry for native hash jumps, so both routes land in the same place.
 */
const ANCHOR_TOP_GAP = 80;

/**
 * Resolve once the element exists, or give up.
 *
 * 🪤 `setTimeout`, NOT `requestAnimationFrame`. A throttled tab stops running
 * frame callbacks — and this promise gates the scroll, so a wait built on frames
 * simply never finishes and the page silently stays where it was. (Native smooth
 * scrolling keeps working in the same tab, which is what makes the frame version
 * look correct while it is not: the compositor drives that, not the page.)
 *
 * A section that never arrives — a block switched off, a stale bookmark — must
 * not hold the scroll open for ever, hence the ceiling.
 */
function waitForElement(selector: string, timeoutMs = 3000, stepMs = 50): Promise<boolean> {
    return new Promise((resolve) => {
        const startedAt = Date.now();
        let lastTop: number | null = null;

        const look = (): void => {
            const el = document.querySelector(selector);
            const timedOut = Date.now() - startedAt > timeoutMs;

            if (el === null) {
                timedOut ? resolve(false) : setTimeout(look, stepMs);

                return;
            }

            /*
             * 🪤 Existing is not enough — the position has to have STOPPED MOVING.
             * The sections appear before the images above them have loaded, so the
             * document is still short and every block is still sliding down the
             * page. Scrolling then is worse than useless: the browser clamps the
             * request to the page's current height, the page grows a moment later,
             * and the reader is left at the top with the address bar claiming they
             * are somewhere else. Two identical measurements mean the layout has
             * settled under the target.
             */
            const top = Math.round(el.getBoundingClientRect().top);

            if (lastTop === top || timedOut) {
                resolve(true);

                return;
            }

            lastTop = top;
            setTimeout(look, stepMs);
        };

        look();
    });
}

export const router = createRouter({
    history: createWebHistory(),
    routes,
    /**
     * Hash addresses are real navigation here: the header menu carries
     * `/#block_Start` and friends over from the live site, so those have to land
     * on the section rather than at the top of the page.
     *
     * 🪤 One frame is not enough. The front page draws its sections from the
     * layout API, so for the first few frames after the route resolves the
     * section named in the address does not exist yet — and the router's scroll
     * is SILENT when its target is missing. Following "Check Results" from
     * another page therefore landed at the top of the home page and looked like
     * a dead menu item (caught 2026-08-27, after every header item became an
     * anchor). Waiting for the element itself, rather than for a frame, is the
     * fix; if it never arrives the page simply stays where it was.
     */
    scrollBehavior(to, _from, saved) {
        if (to.hash) {
            return waitForElement(to.hash).then((found) =>
                found ? { el: to.hash, top: ANCHOR_TOP_GAP, behavior: 'smooth' as const } : { top: 0 },
            );
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

    if (to.meta.permission) {
        const required = Array.isArray(to.meta.permission) ? to.meta.permission : [to.meta.permission];
        if (!required.every((p) => session.can(p))) {
            return { name: 'home' };
        }
    }

    // Competitor (student) session — separate from the admin session above.
    if (to.meta.zone === 'student') {
        const student = useStudentSessionStore();
        await student.ensureLoaded();

        if (!student.isIdentified) {
            return { name: 'home' };
        }
    }

    return true;
});
