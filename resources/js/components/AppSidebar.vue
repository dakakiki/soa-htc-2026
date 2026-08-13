<script setup lang="ts">
import { computed, reactive, type Component } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import {
    IconLayoutDashboard,
    IconBuilding,
    IconUsers,
    IconSettings,
    IconLock,
    IconChevronDown,
    IconChevronUp,
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

const items: NavItem[] = [
    { label: t('nav.dashboard'), icon: IconLayoutDashboard, to: 'dashboard', prefix: 'dashboard' },
    { label: t('nav.venues'), icon: IconBuilding, to: 'venues', prefix: 'venues', perm: 'schools.view' },
    { label: t('nav.users'), icon: IconUsers, to: 'users', prefix: 'users', perm: 'users.manage' },
];

const groups: NavGroup[] = [
    {
        key: 'settings',
        label: t('nav.settings'),
        icon: IconSettings,
        children: [{ label: t('nav.roles'), icon: IconLock, to: 'roles', prefix: 'roles', perm: 'roles.manage' }],
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
const toggle = (g: NavGroup): void => {
    openState[g.key] = !isOpen(g);
};

const itemClass = (active: boolean): string =>
    active ? 'bg-blue-50 text-blue-700' : 'text-gray-600 hover:bg-gray-50';
</script>

<template>
    <aside class="w-16 shrink-0 border-r border-gray-200 bg-white lg:w-60">
        <nav class="space-y-1 p-2">
            <RouterLink
                v-for="item in visibleItems"
                :key="item.to"
                :to="{ name: item.to }"
                :title="item.label"
                class="flex items-center justify-center gap-3 rounded-md px-3 py-2 text-sm lg:justify-start"
                :class="itemClass(isActive(item.prefix))"
            >
                <component :is="item.icon" :size="20" class="shrink-0" />
                <span class="hidden lg:inline">{{ item.label }}</span>
            </RouterLink>

            <div v-for="g in visibleGroups" :key="g.key">
                <button
                    type="button"
                    :title="g.label"
                    class="flex w-full items-center justify-center gap-3 rounded-md px-3 py-2 text-sm lg:justify-start"
                    :class="groupActive(g) ? 'text-blue-700' : 'text-gray-600 hover:bg-gray-50'"
                    @click="toggle(g)"
                >
                    <component :is="g.icon" :size="20" class="shrink-0" />
                    <span class="hidden lg:inline">{{ g.label }}</span>
                    <component :is="isOpen(g) ? IconChevronUp : IconChevronDown" :size="16" class="ml-auto hidden lg:block" />
                </button>
                <div v-show="isOpen(g)" class="space-y-1">
                    <RouterLink
                        v-for="c in g.children"
                        :key="c.to"
                        :to="{ name: c.to }"
                        :title="c.label"
                        class="flex items-center justify-center gap-3 rounded-md px-3 py-2 text-sm lg:justify-start lg:pl-9"
                        :class="itemClass(isActive(c.prefix))"
                    >
                        <component :is="c.icon" :size="18" class="shrink-0" />
                        <span class="hidden lg:inline">{{ c.label }}</span>
                    </RouterLink>
                </div>
            </div>
        </nav>
    </aside>
</template>
