import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { useSessionStore } from '@/stores/session';

declare module 'vue-router' {
    interface RouteMeta {
        requiresAuth?: boolean;
        guestOnly?: boolean;
        permission?: string;
    }
}

const routes: RouteRecordRaw[] = [
    {
        path: '/',
        name: 'home',
        component: () => import('@/pages/HomePage.vue'),
    },
    {
        path: '/login',
        name: 'login',
        component: () => import('@/pages/LoginPage.vue'),
        meta: { guestOnly: true },
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

    return true;
});
