<script setup lang="ts">
import { computed, onMounted, reactive, ref, type Component, type Ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { pendingRegistrationCount } from '@/api/coordinatorRegistrations';
import {
    IconLayoutDashboard,
    IconBuilding,
    IconUsersGroup,
    IconUsers,
    IconUserStar,
    IconUserPlus,
    IconSettings,
    IconLock,
    IconShieldLock,
    IconWorld,
    IconPalette,
    IconCalendarEvent,
    IconCertificate,
    IconStairs,
    IconListCheck,
    IconStack2,
    IconClipboardList,
    IconClipboardCheck,
    IconChecklist,
    IconSend,
    IconFileImport,
    IconFileExport,
    IconChartBar,
    IconArchive,
    IconRotate,
    IconHelpCircle,
    IconFileText,
    IconTag,
    IconClockHour4,
    IconCategory,
    IconWorldWww,
    IconArticle,
    IconNews,
    IconFolders,
    IconPhoto,
    IconLayoutRows,
    IconMenu2,
    IconChevronDown,
    IconChevronUp,
    IconChevronsLeft,
    IconChevronsRight,
} from '@tabler/icons-vue';

interface NavItem {
    label: string;
    icon: Component;
    to: string;
    prefix: string;
    perm?: string;
    /**
     * A count shown beside the label. One entry uses it: the coordinator
     * registration queue (ADR-0053), where nothing else tells anybody that
     * somebody is waiting — both decision mails go to the applicant.
     */
    badge?: Ref<number>;
}
interface NavGroup {
    key: string;
    label: string;
    icon: Component;
    children: NavItem[];
}
// A nav entry is either a single link or an expandable group. They share one
// ordered list so groups and links can be interleaved in any order.
type NavNode = ({ kind: 'item' } & NavItem) | ({ kind: 'group' } & NavGroup);

const { t } = useI18n();
const route = useRoute();
const session = useSessionStore();

const STORAGE_KEY = 'sidebar-collapsed';
const stored = localStorage.getItem(STORAGE_KEY);
const collapsed = ref(stored !== null ? stored === '1' : window.innerWidth < 1024);
function toggleCollapsed(): void {
    collapsed.value = !collapsed.value;
    localStorage.setItem(STORAGE_KEY, collapsed.value ? '1' : '0');
}

/**
 * How many coordinator registrations are waiting (ADR-0053). Read once per
 * mount: it is a nudge towards the queue, not a live counter, and the queue
 * itself shows the real number.
 */
const pendingRegistrations = ref(0);

onMounted(async () => {
    if (!session.can('coordinators.approve')) {
        return;
    }
    try {
        const { data } = await pendingRegistrationCount();
        pendingRegistrations.value = data.data.pending;
    } catch {
        // The badge is a convenience; the menu item stands without it.
    }
});

// One ordered list: Dashboard, Students, Coordinators, Venues, Quizzes,
// then Results, Access, Settings (last).
const nav: NavNode[] = [
    { kind: 'item', label: t('nav.dashboard'), icon: IconLayoutDashboard, to: 'dashboard', prefix: 'dashboard' },
    {
        kind: 'group',
        key: 'students',
        label: t('nav.students'),
        icon: IconUsersGroup,
        children: [
            { label: t('nav.students'), icon: IconUsersGroup, to: 'registrations', prefix: 'registrations', perm: 'students.view' },
            { label: t('nav.difficulty'), icon: IconStairs, to: 'difficulty', prefix: 'difficulty', perm: 'difficulty.manage' },
        ],
    },
    {
        kind: 'group',
        key: 'coordinators',
        label: t('nav.coordinators'),
        icon: IconUserStar,
        children: [
            { label: t('nav.coordinators'), icon: IconUserStar, to: 'coordinators', prefix: 'coordinators', perm: 'coordinators.manage' },
            // The public registration queue (ADR-0053). Its own permission, so a
            // country coordinator sees the people it manages without seeing the
            // strangers asking to be let in.
            {
                label: t('nav.registrationQueue'),
                icon: IconUserPlus,
                to: 'registrationQueue',
                prefix: 'registrationQueue',
                perm: 'coordinators.approve',
                badge: pendingRegistrations,
            },
        ],
    },
    { kind: 'item', label: t('nav.venues'), icon: IconBuilding, to: 'venues', prefix: 'venues', perm: 'schools.edit' },
    {
        kind: 'group',
        key: 'quizzes',
        label: t('nav.quizzes'),
        icon: IconListCheck,
        children: [
            { label: t('nav.quizzesItem'), icon: IconStack2, to: 'quizzes', prefix: 'quizzes', perm: 'content.manage' },
            { label: t('nav.exams'), icon: IconClipboardList, to: 'exams', prefix: 'exams', perm: 'content.manage' },
            { label: t('nav.tests'), icon: IconFileText, to: 'tests', prefix: 'tests', perm: 'content.manage' },
            { label: t('nav.questions'), icon: IconHelpCircle, to: 'questions', prefix: 'questions', perm: 'content.manage' },
            { label: t('nav.tags'), icon: IconTag, to: 'content.tags', prefix: 'content.tags', perm: 'content.manage' },
            { label: t('nav.examRounds'), icon: IconClockHour4, to: 'content.exam-rounds', prefix: 'content.exam-rounds', perm: 'content.manage' },
            { label: t('nav.testType'), icon: IconCategory, to: 'content.test-types', prefix: 'content.test-types', perm: 'content.manage' },
        ],
    },
    {
        kind: 'group',
        key: 'results',
        label: t('nav.results'),
        icon: IconClipboardCheck,
        children: [
            { label: t('nav.grading'), icon: IconChecklist, to: 'grading', prefix: 'grading', perm: 'results.manage' },
            { label: t('nav.publishing'), icon: IconSend, to: 'publishing', prefix: 'publishing', perm: 'results.manage' },
            { label: t('nav.import'), icon: IconFileImport, to: 'results.import', prefix: 'results.import', perm: 'results.manage' },
            { label: t('nav.export'), icon: IconFileExport, to: 'results.export', prefix: 'results.export', perm: 'results.manage' },
            { label: t('nav.reports'), icon: IconChartBar, to: 'reports', prefix: 'reports', perm: 'reports.view' },
            { label: t('nav.archive'), icon: IconArchive, to: 'results.archive', prefix: 'results.archive', perm: 'reports.view' },
            { label: t('nav.reset'), icon: IconRotate, to: 'reset', prefix: 'reset', perm: 'results.manage' },
        ],
    },
    // The public website: its own module, gated by its own permission.
    {
        kind: 'group',
        key: 'website',
        label: t('nav.website'),
        icon: IconWorldWww,
        children: [
            { label: t('nav.pages'), icon: IconArticle, to: 'cms.pages', prefix: 'cms.pages', perm: 'cms.manage' },
            { label: t('nav.posts'), icon: IconNews, to: 'cms.posts', prefix: 'cms.posts', perm: 'cms.manage' },
            { label: t('nav.postCategories'), icon: IconFolders, to: 'cms.categories', prefix: 'cms.categories', perm: 'cms.manage' },
            { label: t('nav.media'), icon: IconPhoto, to: 'cms.media', prefix: 'cms.media', perm: 'cms.manage' },
            { label: t('nav.menus'), icon: IconMenu2, to: 'cms.menus', prefix: 'cms.menus', perm: 'cms.manage' },
            { label: t('nav.layout'), icon: IconLayoutRows, to: 'cms.layout', prefix: 'cms.layout', perm: 'cms.manage' },
        ],
    },
    // Access and Settings stay last.
    {
        kind: 'group',
        key: 'access',
        label: t('nav.access'),
        icon: IconShieldLock,
        children: [
            { label: t('nav.users'), icon: IconUsers, to: 'users', prefix: 'users', perm: 'users.manage' },
            { label: t('nav.roles'), icon: IconLock, to: 'roles', prefix: 'roles', perm: 'roles.manage' },
        ],
    },
    {
        kind: 'group',
        key: 'settings',
        label: t('nav.settings'),
        icon: IconSettings,
        children: [
            { label: t('nav.season'), icon: IconCalendarEvent, to: 'settings.season', prefix: 'settings.season', perm: 'settings.manage' },
            { label: t('nav.locations'), icon: IconWorld, to: 'locations', prefix: 'locations', perm: 'locations.manage' },
            { label: t('nav.certificate'), icon: IconCertificate, to: 'settings.certificate', prefix: 'settings.certificate', perm: 'settings.manage' },
            { label: t('nav.theme'), icon: IconPalette, to: 'settings.theme', prefix: 'settings.theme', perm: 'settings.manage' },
        ],
    },
];

const canSee = (perm?: string): boolean => !perm || session.can(perm);
const visibleNav = computed<NavNode[]>(() =>
    nav
        .map((n) => (n.kind === 'group' ? { ...n, children: n.children.filter((c) => canSee(c.perm)) } : n))
        .filter((n) => (n.kind === 'group' ? n.children.length > 0 : canSee(n.perm)))
);

function isActive(prefix: string): boolean {
    const name = route.name?.toString() ?? '';
    return name === prefix || name.startsWith(`${prefix}.`);
}
const groupActive = (g: NavGroup): boolean => g.children.some((c) => isActive(c.prefix));

const openState = reactive<Record<string, boolean>>({});
const isOpen = (g: NavGroup): boolean => openState[g.key] ?? groupActive(g);
function toggle(g: NavGroup): void {
    // Expanding a group while collapsed also opens the rail so labels are visible.
    if (collapsed.value) {
        collapsed.value = false;
        localStorage.setItem(STORAGE_KEY, '0');
    }
    openState[g.key] = !isOpen(g);
}

const itemClass = (active: boolean): string =>
    active ? 'bg-brand-primary-soft text-brand-primary' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900';

// Right-side hover tooltip shown next to icon-rail items when the sidebar is collapsed.
const railTip =
    'pointer-events-none absolute left-full top-1/2 z-50 ml-2 -translate-y-1/2 whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-lg transition-opacity duration-100 group-hover/tt:opacity-100';
</script>

<template>
    <aside
        class="flex flex-col shrink-0 border-r border-gray-200 bg-white transition-[width] duration-150"
        :class="collapsed ? 'w-16' : 'w-60'"
    >
        <nav class="flex-1 space-y-1 p-2" :class="collapsed ? 'overflow-visible' : 'overflow-y-auto'">
            <template v-for="node in visibleNav" :key="node.kind === 'group' ? node.key : node.to">
                <RouterLink
                    v-if="node.kind === 'item'"
                    :to="{ name: node.to }"
                    class="group/tt relative flex items-center gap-3 rounded-md px-3 py-2 text-sm"
                    :class="[itemClass(isActive(node.prefix)), { 'justify-center': collapsed }]"
                >
                    <component :is="node.icon" :size="20" class="shrink-0" />
                    <span v-show="!collapsed">{{ node.label }}</span>
                    <span v-if="collapsed" :class="railTip">{{ node.label }}</span>
                </RouterLink>

                <div v-else>
                    <button
                        type="button"
                        class="group/tt relative flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm"
                        :class="[
                            groupActive(node) ? 'text-brand-primary hover:bg-gray-100' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900',
                            { 'justify-center': collapsed },
                        ]"
                        @click="toggle(node)"
                    >
                        <component :is="node.icon" :size="20" class="shrink-0" />
                        <span v-show="!collapsed">{{ node.label }}</span>
                        <component
                            v-show="!collapsed"
                            :is="isOpen(node) ? IconChevronUp : IconChevronDown"
                            :size="16"
                            class="ml-auto"
                        />
                        <span v-if="collapsed" :class="railTip">{{ node.label }}</span>
                    </button>
                    <div v-show="isOpen(node)" class="space-y-1">
                        <RouterLink
                            v-for="c in node.children"
                            :key="c.to"
                            :to="{ name: c.to }"
                            class="group/tt relative flex items-center gap-3 rounded-md px-3 py-2 text-sm"
                            :class="[itemClass(isActive(c.prefix)), collapsed ? 'justify-center' : 'pl-9']"
                        >
                            <component :is="c.icon" :size="18" class="shrink-0" />
                            <span v-show="!collapsed">{{ c.label }}</span>
                            <span
                                v-if="c.badge && c.badge.value > 0"
                                v-show="!collapsed"
                                class="ml-auto rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800"
                            >{{ c.badge.value }}</span>
                            <span v-if="collapsed" :class="railTip">{{ c.label }}</span>
                        </RouterLink>
                    </div>
                </div>
            </template>
        </nav>

        <div class="flex border-t border-gray-200 p-2" :class="collapsed ? 'justify-center' : 'justify-end'">
            <button
                type="button"
                :title="t('nav.toggleMenu')"
                :aria-label="t('nav.toggleMenu')"
                class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-gray-300 bg-gray-100 text-gray-600 hover:bg-gray-200"
                @click="toggleCollapsed"
            >
                <component :is="collapsed ? IconChevronsRight : IconChevronsLeft" :size="18" />
            </button>
        </div>
    </aside>
</template>
