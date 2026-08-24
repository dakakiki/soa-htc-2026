<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconPencil, IconPlus, IconTrash } from '@tabler/icons-vue';
import {
    createLayoutBlock,
    deleteLayoutBlock,
    getLayoutRegistry,
    listLayoutBlocks,
    saveLayoutOrder,
    updateLayoutBlock,
} from '@/api/cmsLayout';
import { apiErrorMessage } from '@/api/http';
import { useConfirmStore } from '@/stores/confirm';
import LayoutBlockEditor from '@/components/cms/LayoutBlockEditor.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import OrderableList from '@/components/OrderableList.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import Tooltip from '@/components/Tooltip.vue';
import type { CmsLayoutBlock, LayoutRegistry, LayoutTypeInfo, LayoutZoneInfo } from '@/types/models';

/**
 * The layout editor (ADR-0043): one zone at a time, its sections top to bottom
 * in the order a visitor meets them.
 *
 * Legacy listed every module of every position in one paginated table, so the
 * composition of a page was invisible — this list IS the page.
 */
const { t } = useI18n();
const confirm = useConfirmStore();

const registry = ref<LayoutRegistry | null>(null);
const zone = ref<string>('');
const blocks = ref<CmsLayoutBlock[]>([]);

const loading = ref(true);
const error = ref<string | null>(null);
const adding = ref(false);
const editing = ref<CmsLayoutBlock | null>(null);

const zones = computed<LayoutZoneInfo[]>(() => registry.value?.zones ?? []);
const currentZone = computed<LayoutZoneInfo | null>(
    () => zones.value.find((z) => z.key === zone.value) ?? null,
);

const typeOf = (key: string): LayoutTypeInfo | null =>
    currentZone.value?.types.find((x) => x.key === key) ?? null;

/** How many of a type are already placed, so a singleton can refuse a second. */
const used = (key: string): number => blocks.value.filter((b) => b.type === key).length;

const isFull = (type: LayoutTypeInfo): boolean => type.max !== null && used(type.key) >= type.max;

/** Same icon chip the row actions use everywhere else in the admin. */
const chip = 'inline-flex h-7 w-7 items-center justify-center rounded-md border border-gray-300 bg-gray-100 hover:bg-gray-200';

/** A section's own heading if it has one, else what the type is called. */
function title(block: CmsLayoutBlock): string {
    const raw = block.content?.title;
    return typeof raw === 'string' && raw.trim() !== '' ? raw : block.type_label;
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        if (registry.value === null) {
            const { data } = await getLayoutRegistry();
            registry.value = data.data;
            zone.value = data.data.zones[0]?.key ?? '';
        }
        if (zone.value !== '') {
            const { data } = await listLayoutBlocks(zone.value);
            blocks.value = data.data;
        }
    } catch (e) {
        error.value = apiErrorMessage(e, t('layout.loadFailed'));
    } finally {
        loading.value = false;
    }
}

/** Dropping a section rewrites several positions, so the whole list is saved. */
async function onReorder(next: CmsLayoutBlock[]): Promise<void> {
    blocks.value = next;
    try {
        const { data } = await saveLayoutOrder(zone.value, next.map((b) => b.id));
        blocks.value = data.data;
    } catch (e) {
        error.value = apiErrorMessage(e, t('layout.saveFailed'));
        await load();
    }
}

async function toggle(block: CmsLayoutBlock, status: boolean): Promise<void> {
    try {
        const { data } = await updateLayoutBlock(block.id, { status });
        Object.assign(block, data.data);
    } catch (e) {
        error.value = apiErrorMessage(e, t('layout.saveFailed'));
    }
}

async function add(type: LayoutTypeInfo): Promise<void> {
    adding.value = false;
    error.value = null;
    try {
        const { data } = await createLayoutBlock(zone.value, { type: type.key });
        blocks.value = [...blocks.value, data.data];
        editing.value = data.data;
    } catch (e) {
        error.value = apiErrorMessage(e, t('layout.saveFailed'));
    }
}

async function remove(block: CmsLayoutBlock): Promise<void> {
    const ok = await confirm.ask({
        title: t('layout.deleteTitle'),
        message: t('layout.deleteBody', { name: title(block) }),
    });
    if (!ok) {
        return;
    }
    try {
        await deleteLayoutBlock(block.id);
        blocks.value = blocks.value.filter((b) => b.id !== block.id);
    } catch (e) {
        error.value = apiErrorMessage(e, t('layout.saveFailed'));
    }
}

function onSaved(block: CmsLayoutBlock): void {
    const i = blocks.value.findIndex((b) => b.id === block.id);
    if (i !== -1) {
        blocks.value[i] = block;
    }
    editing.value = null;
}

onMounted(load);
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('layout.title') }}</h1>
                <p v-if="currentZone" class="mt-1 text-sm text-gray-500">{{ currentZone.description }}</p>
            </div>

            <div class="relative">
                <button type="button"
                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover"
                    @click="adding = !adding">
                    <IconPlus :size="16" />
                    {{ $t('layout.addSection') }}
                </button>

                <div v-if="adding && currentZone"
                    class="absolute right-0 z-20 mt-2 w-72 rounded-lg border border-gray-200 bg-white p-1 shadow-xl">
                    <button v-for="type in currentZone.types" :key="type.key" type="button"
                        :disabled="isFull(type)"
                        class="flex w-full items-center justify-between rounded-md px-3 py-2 text-left text-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
                        @click="add(type)">
                        <span>{{ type.label }}</span>
                        <span v-if="type.max !== null" class="font-mono text-xs text-gray-400">
                            {{ used(type.key) }}/{{ type.max }}
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <!-- More than one zone only appears when the shell grows one; with a
             single zone a picker would be furniture. -->
        <label v-if="zones.length > 1" class="block max-w-xs">
            <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('layout.zone') }}</span>
            <select v-model="zone" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" @change="load">
                <option v-for="z in zones" :key="z.key" :value="z.key">{{ z.label }}</option>
            </select>
        </label>

        <div class="relative min-h-[8rem] rounded-lg border border-gray-200 bg-white p-4">
            <LoadingOverlay v-if="loading" />

            <!-- `removable` stays off: dropping a section from the list is not the
                 same as deleting it, so the delete below asks first and goes to
                 the server. -->
            <OrderableList :model-value="blocks" :empty-text="$t('layout.empty')" :removable="false"
                @update:model-value="onReorder">
                <template #item="{ item }">
                    <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-[11px] uppercase tracking-wider text-gray-500">
                        {{ item.type }}
                    </span>
                    <span class="flex-1 truncate font-medium" :class="item.status ? '' : 'text-gray-400'">
                        {{ title(item) }}
                    </span>

                    <!-- The switch says what it will do, not what it is. -->
                    <Tooltip :text="item.status ? $t('layout.hideSection') : $t('layout.showSection')">
                        <ToggleSwitch :model-value="item.status"
                            :aria-label="item.status ? $t('layout.hideSection') : $t('layout.showSection')"
                            @update:model-value="toggle(item, $event)" />
                    </Tooltip>
                </template>

                <template #actions="{ item }">
                    <Tooltip :text="$t('common.edit')">
                        <button type="button" :aria-label="$t('common.edit')" :class="[chip, 'text-green-600']"
                            @click="editing = item">
                            <IconPencil :size="16" />
                        </button>
                    </Tooltip>
                    <Tooltip :text="$t('common.remove')">
                        <button type="button" :aria-label="$t('common.remove')" :class="[chip, 'text-red-600']"
                            @click="remove(item)">
                            <IconTrash :size="16" />
                        </button>
                    </Tooltip>
                </template>
            </OrderableList>
        </div>

        <LayoutBlockEditor v-if="editing && registry && typeOf(editing.type)"
            :block="editing"
            :type="typeOf(editing.type) as LayoutTypeInfo"
            :registry="registry"
            @close="editing = null"
            @saved="onSaved" />
    </section>
</template>
