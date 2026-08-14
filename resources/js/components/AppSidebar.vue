<script setup lang="ts">
import { computed, reactive, ref, type Component } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import {
    IconLayoutDashboard,
    IconBuilding,
    IconUsers,
    IconUserStar,
    IconSettings,
    IconLock,
    IconWorld,
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

const items: NavItem[] = [
    { label: t('nav.dashboard'), icon: IconLayoutDashboard, to: 'dashboard', prefix: 'dashboard' },
    { label: t('nav.venues'), icon: IconBuilding, to: 'venues', prefix: 'venues', perm: 'schools.view' },
    { label: t('nav.users'), icon: IconUsers, to: 'users', prefix: 'users', perm: 'users.manage' },
    { label: t('nav.coordinators'), icon: IconUserStar, to: 'coordinators', prefix: 'coordinators', perm: 'users.manage' },
];

const groups: NavGroup[] = [
    {
        key: 'settings',
        label: t('nav.settings'),
        icon: IconSettings,
        children: [
            { label: t('nav.locations'), icon: IconWorld, to: 'locations', prefix: 'locations', perm: 'locations.manage' },
            { label: t('nav.roles'), icon: IconLock, to: 'roles', prefix: 'roles', perm: 'roles.manage' },
        ],
    },
];

const canSee = (perm?: string): boolean => !perm || session.can(perm);
const visibleItems = computed(() => items.filter((i) => canSee(i.perm)));
const visibleGroups = computed(() =>
    groups
        .map((g) => ({ ...g, children: g.children.filter((c) => canSee(c.perm)) }))
        .filter((g) => g.children.length > 0)
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
    active ? 'bg-blue-50 text-blue-700' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900';

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
            <RouterLink
                v-for="item in visibleItems"
                :key="item.to"
                :to="{ name: item.to }"
                class="group/tt relative flex items-center gap-3 rounded-md px-3 py-2 text-sm"
                :class="[itemClass(isActive(item.prefix)), { 'justify-center': collapsed }]"
            >
                <component :is="item.icon" :size="20" class="shrink-0" />
                <span v-show="!collapsed">{{ item.label }}</span>
                <span v-if="collapsed" :class="railTip">{{ item.label }}</span>
            </RouterLink>

            <div v-for="g in visibleGroups" :key="g.key">
                <button
                    type="button"
                    class="group/tt relative flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm"
                    :class="[
                        groupActive(g) ? 'text-blue-700 hover:bg-gray-100' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900',
                        { 'justify-center': collapsed },
                    ]"
                    @click="toggle(g)"
                >
                    <component :is="g.icon" :size="20" class="shrink-0" />
                    <span v-show="!collapsed">{{ g.label }}</span>
                    <component
                        v-show="!collapsed"
                        :is="isOpen(g) ? IconChevronUp : IconChevronDown"
                        :size="16"
                        class="ml-auto"
                    />
                    <span v-if="collapsed" :class="railTip">{{ g.label }}</span>
                </button>
                <div v-show="isOpen(g)" class="space-y-1">
                    <RouterLink
                        v-for="c in g.children"
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
