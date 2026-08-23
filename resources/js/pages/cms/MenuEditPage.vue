<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
    IconPlus, IconGripVertical, IconArrowUp, IconArrowDown, IconTrash,
    IconPencil, IconIndentIncrease, IconIndentDecrease, IconExternalLink,
} from '@tabler/icons-vue';
import { getMenu, updateMenu, saveMenuItems, menuTargets } from '@/api/menus';
import { apiErrorMessage } from '@/api/http';
import ButtonGroup from '@/components/ButtonGroup.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import Tooltip from '@/components/Tooltip.vue';
import type { CmsMenuItem, CmsMenuItemPayload, CmsMenuItemType, CmsMenuTarget } from '@/types/models';

/**
 * The arrangement editor. Items are held client-side as a tree and written back
 * in one call — dragging a single row changes several rows' order and possibly
 * their parent, so saving row by row would be both chattier and less atomic.
 *
 * Two levels: an item may hold children, a child may not. That covers a submenu
 * without asking anyone to reason about arbitrary depth.
 */
const route = useRoute();
const { t } = useI18n();

const id = computed(() => Number(route.params.id));

interface Draft {
    /** Client-side identity: a new item has no id yet, but v-for needs a key. */
    key: number;
    type: CmsMenuItemType;
    page_id: number | null;
    post_id: number | null;
    category_id: number | null;
    url: string | null;
    label: string | null;
    target_name: string | null;
    link_target: string;
    children: Draft[];
}

let uid = 0;

const menuName = ref('');
const menuSlug = ref('');
const items = ref<Draft[]>([]);
const loading = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
const dirty = ref(false);

/* ------------------------------------------------------------------ drag */

const dragging = ref<{ list: Draft[]; index: number } | null>(null);

function onDragStart(list: Draft[], index: number): void {
    dragging.value = { list, index };
}

/** Reordering happens within one level; crossing levels is what indent is for. */
function onDragOver(list: Draft[], index: number): void {
    const from = dragging.value;
    if (!from || from.list !== list || from.index === index) {
        return;
    }
    const [moved] = list.splice(from.index, 1);
    list.splice(index, 0, moved);
    dragging.value = { list, index };
    dirty.value = true;
}

function move(list: Draft[], from: number, to: number): void {
    if (to < 0 || to >= list.length) {
        return;
    }
    const [moved] = list.splice(from, 1);
    list.splice(to, 0, moved);
    dirty.value = true;
}

/* --------------------------------------------------------------- nesting */

/** An item with children of its own cannot become a child — two levels only. */
const canIndent = (index: number): boolean => index > 0 && items.value[index].children.length === 0;

function indent(index: number): void {
    if (!canIndent(index)) {
        return;
    }
    const [moved] = items.value.splice(index, 1);
    items.value[index - 1].children.push(moved);
    dirty.value = true;
}

function outdent(parentIndex: number, childIndex: number): void {
    const [moved] = items.value[parentIndex].children.splice(childIndex, 1);
    items.value.splice(parentIndex + 1, 0, moved);
    dirty.value = true;
}

function removeAt(list: Draft[], index: number): void {
    list.splice(index, 1);
    dirty.value = true;
}

/* ------------------------------------------------------------ item modal */

const modalOpen = ref(false);
/** The list the edited item belongs to, and its index; null index = a new item. */
const editing = ref<{ list: Draft[]; index: number | null }>({ list: [], index: null });

const form = reactive({
    type: 'page' as CmsMenuItemType,
    target_id: null as number | null,
    target_name: null as string | null,
    url: '',
    label: '',
    link_target: '_self',
});

const targets = ref<CmsMenuTarget[]>([]);
const targetsLoading = ref(false);
const targetOptions = computed<SearchSelectOption[]>(() =>
    targets.value.map((x) => ({ id: x.id, label: x.label, sub: x.slug })),
);
const selectedTarget = computed<SearchSelectOption | null>(() =>
    form.target_id === null ? null : { id: form.target_id, label: form.target_name ?? '' },
);

const typeOptions = computed(() => [
    { value: 'page', label: t('cms.menu.typePage') },
    { value: 'post', label: t('cms.menu.typePost') },
    { value: 'category', label: t('cms.menu.typeCategory') },
    { value: 'custom', label: t('cms.menu.typeCustom') },
]);
const targetOptionsGroup = computed(() => [
    { value: '_self', label: t('cms.menu.sameTab'), activeClass: 'bg-green-500 text-white' },
    { value: '_blank', label: t('cms.menu.newTab'), activeClass: 'bg-green-500 text-white' },
]);

async function loadTargets(term = ''): Promise<void> {
    if (form.type === 'custom') {
        targets.value = [];
        return;
    }
    targetsLoading.value = true;
    try {
        const { data } = await menuTargets(form.type, term || undefined);
        targets.value = data.data;
    } catch {
        targets.value = [];
    } finally {
        targetsLoading.value = false;
    }
}

async function onTypeChange(): Promise<void> {
    form.target_id = null;
    form.target_name = null;
    await loadTargets();
}

function openItem(list: Draft[], index: number | null): void {
    editing.value = { list, index };
    const draft = index === null ? null : list[index];

    form.type = draft?.type ?? 'page';
    form.target_id = draft ? (draft.page_id ?? draft.post_id ?? draft.category_id) : null;
    form.target_name = draft?.target_name ?? null;
    form.url = draft?.url ?? '';
    form.label = draft?.label ?? '';
    form.link_target = draft?.link_target ?? '_self';

    modalOpen.value = true;
    void loadTargets();
}

function onTargetPicked(value: number | null): void {
    form.target_id = value;
    form.target_name = targets.value.find((x) => x.id === value)?.label ?? null;
}

function submitItem(): void {
    const draft: Draft = {
        key: editing.value.index === null ? ++uid : editing.value.list[editing.value.index].key,
        type: form.type,
        page_id: form.type === 'page' ? form.target_id : null,
        post_id: form.type === 'post' ? form.target_id : null,
        category_id: form.type === 'category' ? form.target_id : null,
        url: form.type === 'custom' ? form.url : null,
        label: form.label || null,
        target_name: form.type === 'custom' ? null : form.target_name,
        link_target: form.link_target,
        children: editing.value.index === null ? [] : editing.value.list[editing.value.index].children,
    };

    if (editing.value.index === null) {
        editing.value.list.push(draft);
    } else {
        editing.value.list[editing.value.index] = draft;
    }

    modalOpen.value = false;
    dirty.value = true;
}

/* ------------------------------------------------------------------ save */

const displayLabel = (d: Draft): string => d.label || d.target_name || d.url || t('cms.menu.untitled');

const typeLabel = (type: CmsMenuItemType): string =>
    typeOptions.value.find((o) => o.value === type)?.label ?? type;

function toPayload(list: Draft[]): CmsMenuItemPayload[] {
    return list.map((d) => ({
        type: d.type,
        page_id: d.page_id,
        post_id: d.post_id,
        category_id: d.category_id,
        url: d.url,
        label: d.label,
        link_target: d.link_target,
        children: toPayload(d.children),
    }));
}

function toDraft(item: CmsMenuItem): Draft {
    return {
        key: ++uid,
        type: item.type,
        page_id: item.page_id,
        post_id: item.post_id,
        category_id: item.category_id,
        url: item.url,
        label: item.label,
        target_name: item.target_name,
        link_target: item.link_target,
        children: (item.children ?? []).map(toDraft),
    };
}

async function save(): Promise<void> {
    saving.value = true;
    error.value = null;
    try {
        await updateMenu(id.value, { name: menuName.value });
        const { data } = await saveMenuItems(id.value, toPayload(items.value));
        items.value = (data.data.items ?? []).map(toDraft);
        dirty.value = false;
    } catch (e) {
        error.value = apiErrorMessage(e, t('cms.menu.saveFailed'));
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    loading.value = true;
    try {
        const { data } = await getMenu(id.value);
        menuName.value = data.data.name;
        menuSlug.value = data.data.slug;
        items.value = (data.data.items ?? []).map(toDraft);
    } catch (e) {
        error.value = apiErrorMessage(e, t('cms.menu.error'));
    } finally {
        loading.value = false;
    }
});

const field = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm';
const row = 'flex items-center gap-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm';
const iconBtn = 'text-gray-400 hover:text-gray-700 disabled:opacity-30';
</script>

<template>
    <section class="space-y-5">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <RouterLink :to="{ name: 'cms.menus' }" class="hover:text-gray-900">{{ $t('cms.menu.title') }}</RouterLink>
            <span>/</span>
            <span class="text-gray-900">{{ menuName }}</span>
        </div>

        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold tracking-tight">{{ $t('cms.menu.edit') }}</h1>
            <button type="button" :disabled="saving || !dirty"
                class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50"
                @click="save">
                {{ saving ? $t('common.saving') : $t('common.save') }}
            </button>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="relative rounded-lg border border-gray-200 bg-white p-6">
            <LoadingOverlay v-if="loading || saving" :message="saving ? $t('common.saving') : undefined" />

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $t('cms.menu.name') }}</label>
                    <input v-model="menuName" type="text" maxlength="255" :class="field" @input="dirty = true" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $t('cms.menu.handle') }}</label>
                    <p class="mt-1 rounded-md bg-gray-50 px-3 py-2 font-mono text-sm text-gray-600">{{ menuSlug }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ $t('cms.menu.handleHint') }}</p>
                </div>
            </div>

            <div class="mt-6 border-t border-gray-200 pt-5">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900">{{ $t('cms.menu.items') }}</h2>
                    <button type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50"
                        @click="openItem(items, null)">
                        <IconPlus :size="16" />{{ $t('cms.menu.addItem') }}
                    </button>
                </div>

                <ol class="space-y-2">
                    <li v-for="(item, i) in items" :key="item.key">
                        <div :class="row" draggable="true"
                            @dragstart="onDragStart(items, i)" @dragover.prevent="onDragOver(items, i)"
                            @dragend="dragging = null" @drop.prevent="dragging = null">
                            <Tooltip :text="$t('common.dragReorder')">
                                <span class="shrink-0 cursor-grab text-gray-300 hover:text-gray-500 active:cursor-grabbing"
                                    :aria-label="$t('common.dragReorder')"><IconGripVertical :size="16" /></span>
                            </Tooltip>

                            <span class="min-w-0 flex-1 truncate font-medium text-gray-900">{{ displayLabel(item) }}</span>
                            <span class="shrink-0 rounded bg-gray-200 px-2 py-0.5 text-xs text-gray-600">{{ typeLabel(item.type) }}</span>
                            <Tooltip v-if="item.link_target === '_blank'" :text="$t('cms.menu.newTab')">
                                <span class="shrink-0 text-gray-400" :aria-label="$t('cms.menu.newTab')"><IconExternalLink :size="14" /></span>
                            </Tooltip>

                            <div class="flex shrink-0 items-center gap-1 border-l border-gray-200 pl-3">
                                <Tooltip :text="$t('common.moveUp')">
                                    <button type="button" :class="iconBtn" :disabled="i === 0" :aria-label="$t('common.moveUp')"
                                        @click="move(items, i, i - 1)"><IconArrowUp :size="16" /></button>
                                </Tooltip>
                                <Tooltip :text="$t('common.moveDown')">
                                    <button type="button" :class="iconBtn" :disabled="i === items.length - 1" :aria-label="$t('common.moveDown')"
                                        @click="move(items, i, i + 1)"><IconArrowDown :size="16" /></button>
                                </Tooltip>
                                <Tooltip :text="$t('cms.menu.indent')">
                                    <button type="button" :class="iconBtn" :disabled="!canIndent(i)" :aria-label="$t('cms.menu.indent')"
                                        @click="indent(i)"><IconIndentIncrease :size="16" /></button>
                                </Tooltip>
                            </div>

                            <div class="flex shrink-0 items-center gap-2 border-l border-gray-200 pl-3">
                                <Tooltip :text="$t('common.edit')">
                                    <button type="button" class="text-green-600 hover:text-green-700" :aria-label="$t('common.edit')"
                                        @click="openItem(items, i)"><IconPencil :size="16" /></button>
                                </Tooltip>
                                <Tooltip :text="$t('common.remove')">
                                    <button type="button" class="text-red-600 hover:text-red-700" :aria-label="$t('common.remove')"
                                        @click="removeAt(items, i)"><IconTrash :size="16" /></button>
                                </Tooltip>
                            </div>
                        </div>

                        <!-- Children: one level, indented, dragged among themselves. -->
                        <ol v-if="item.children.length" class="ml-8 mt-2 space-y-2 border-l-2 border-gray-200 pl-4">
                            <li v-for="(child, j) in item.children" :key="child.key" :class="row" draggable="true"
                                @dragstart="onDragStart(item.children, j)" @dragover.prevent="onDragOver(item.children, j)"
                                @dragend="dragging = null" @drop.prevent="dragging = null">
                                <Tooltip :text="$t('common.dragReorder')">
                                    <span class="shrink-0 cursor-grab text-gray-300 hover:text-gray-500 active:cursor-grabbing"
                                        :aria-label="$t('common.dragReorder')"><IconGripVertical :size="16" /></span>
                                </Tooltip>

                                <span class="min-w-0 flex-1 truncate text-gray-800">{{ displayLabel(child) }}</span>
                                <span class="shrink-0 rounded bg-gray-200 px-2 py-0.5 text-xs text-gray-600">{{ typeLabel(child.type) }}</span>

                                <div class="flex shrink-0 items-center gap-1 border-l border-gray-200 pl-3">
                                    <Tooltip :text="$t('common.moveUp')">
                                        <button type="button" :class="iconBtn" :disabled="j === 0" :aria-label="$t('common.moveUp')"
                                            @click="move(item.children, j, j - 1)"><IconArrowUp :size="16" /></button>
                                    </Tooltip>
                                    <Tooltip :text="$t('common.moveDown')">
                                        <button type="button" :class="iconBtn" :disabled="j === item.children.length - 1" :aria-label="$t('common.moveDown')"
                                            @click="move(item.children, j, j + 1)"><IconArrowDown :size="16" /></button>
                                    </Tooltip>
                                    <Tooltip :text="$t('cms.menu.outdent')">
                                        <button type="button" :class="iconBtn" :aria-label="$t('cms.menu.outdent')"
                                            @click="outdent(i, j)"><IconIndentDecrease :size="16" /></button>
                                    </Tooltip>
                                </div>

                                <div class="flex shrink-0 items-center gap-2 border-l border-gray-200 pl-3">
                                    <Tooltip :text="$t('common.edit')">
                                        <button type="button" class="text-green-600 hover:text-green-700" :aria-label="$t('common.edit')"
                                            @click="openItem(item.children, j)"><IconPencil :size="16" /></button>
                                    </Tooltip>
                                    <Tooltip :text="$t('common.remove')">
                                        <button type="button" class="text-red-600 hover:text-red-700" :aria-label="$t('common.remove')"
                                            @click="removeAt(item.children, j)"><IconTrash :size="16" /></button>
                                    </Tooltip>
                                </div>
                            </li>
                        </ol>
                    </li>

                    <li v-if="items.length === 0"
                        class="flex items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-3 text-sm text-gray-400">
                        <IconPlus :size="16" />{{ $t('cms.menu.emptyItems') }}
                    </li>
                </ol>

                <p class="mt-3 text-xs text-gray-500">{{ $t('cms.menu.nestingHint') }}</p>
            </div>
        </div>

        <!-- Add / edit one item -->
        <div v-if="modalOpen" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" @click.self="modalOpen = false">
            <div class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold">{{ editing.index === null ? $t('cms.menu.addItem') : $t('cms.menu.editItem') }}</h2>

                <form class="mt-4 space-y-4" @submit.prevent="submitItem">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.menu.linkTo') }}</label>
                        <select v-model="form.type" :class="field" @change="onTypeChange">
                            <option v-for="o in typeOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                    </div>

                    <div v-if="form.type !== 'custom'">
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.menu.target') }} *</label>
                        <div class="mt-1">
                            <SearchSelect :model-value="form.target_id" :options="targetOptions" :loading="targetsLoading"
                                remote :selected-option="selectedTarget" :placeholder="$t('cms.menu.pickTarget')"
                                :search-placeholder="$t('common.search')"
                                @search="loadTargets" @update:model-value="onTargetPicked" />
                        </div>
                    </div>

                    <div v-else>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.menu.url') }} *</label>
                        <input v-model="form.url" type="text" required maxlength="500" :class="field" placeholder="https://example.org" />
                        <p class="mt-1 text-xs text-gray-500">{{ $t('cms.menu.urlHint') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.menu.label') }}</label>
                        <input v-model="form.label" type="text" maxlength="255" :class="field"
                            :placeholder="form.target_name ?? $t('cms.menu.labelAuto')" />
                        <p class="mt-1 text-xs text-gray-500">{{ $t('cms.menu.labelHint') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.menu.opensIn') }}</label>
                        <div class="mt-2">
                            <ButtonGroup v-model="form.link_target" :options="targetOptionsGroup" />
                        </div>
                    </div>

                    <div class="flex items-center justify-between border-t border-gray-200 pt-4">
                        <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50"
                            @click="modalOpen = false">{{ $t('common.cancel') }}</button>
                        <button type="submit"
                            class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover">
                            {{ $t('common.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</template>
