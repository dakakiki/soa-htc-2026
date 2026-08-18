<script setup lang="ts">
import { computed, reactive, ref, type Component } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import {
    IconLayoutDashboard,
    IconBuilding,
    IconUsersGroup,
    IconUsers,
    IconUserStar,
    IconSettings,
    IconLock,
    IconShieldLock,
    IconWorld,
    IconPalette,
    IconStairs,
    IconListCheck,
    IconStack2,
    IconClipboardList,
    IconClipboardCheck,
    IconChecklist,
    IconSend,
    IconChartBar,
    IconRotate,
    IconHelpCircle,
    IconFileText,
    IconTag,
    IconClockHour4,
    IconCategory,
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
            { label: t('nav.students'), icon: IconUsersGroup, to: 'registrations', prefix: 'registrations', perm: 'schools.view' },
            { label: t('nav.difficulty'), icon: IconStairs, to: 'difficulty', prefix: 'difficulty', perm: 'difficulty.manage' },
        ],
    },
    { kind: 'item', label: t('nav.coordinators'), icon: IconUserStar, to: 'coordinators', prefix: 'coordinators', perm: 'users.manage' },
    { kind: 'item', label: t('nav.venues'), icon: IconBuilding, to: 'venues', prefix: 'venues', perm: 'schools.view' },
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
            { label: t('nav.reports'), icon: IconChartBar, to: 'reports', prefix: 'reports', perm: 'reports.view' },
            { label: t('nav.reset'), icon: IconRotate, to: 'reset', prefix: 'reset', perm: 'results.manage' },
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
            { label: t('nav.locations'), icon: IconWorld, to: 'locations', prefix: 'locations', perm: 'locations.manage' },
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
