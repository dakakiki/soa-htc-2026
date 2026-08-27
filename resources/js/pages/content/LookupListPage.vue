<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSessionStore } from '@/stores/session';
import { useConfirmStore } from '@/stores/confirm';
import { testTypesApi, examRoundsApi, questionTagsApi, type Lookup } from '@/api/content';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import Tooltip from '@/components/Tooltip.vue';
import { IconPencil, IconTrash, IconPlus, IconGripVertical } from '@tabler/icons-vue';

const props = defineProps<{ kind: 'testType' | 'examRound' | 'tag' }>();

const { t } = useI18n();
const session = useSessionStore();
const confirm = useConfirmStore();
const canManage = computed(() => session.can('content.manage'));

const CONFIG = {
    testType: { api: testTypesApi, title: 'content.testTypes', add: 'content.addTestType', hasActive: false, ordered: false },
    // Rounds run in an order and everything else reads it from here, so this is
    // the one lookup that shows its position instead of its row id.
    examRound: { api: examRoundsApi, title: 'content.examRounds', add: 'content.addExamRound', hasActive: true, ordered: true },
    tag: { api: questionTagsApi, title: 'content.tags', add: 'content.addTag', hasActive: false, ordered: false },
} as const;
const cfg = computed(() => CONFIG[props.kind]);

const chip = 'inline-flex h-7 w-7 items-center justify-center rounded-md border border-gray-300 bg-gray-100 hover:bg-gray-200';

const items = ref<Lookup[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const modal = reactive<{ open: boolean; editing: Lookup | null; name: string; active: boolean; saving: boolean; error: string | null }>({
    open: false, editing: null, name: '', active: true, saving: false, error: null,
});

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await cfg.value.api.list();
        items.value = data.data;
    } catch (e) {
        error.value = apiErrorMessage(e, t('content.error'));
    } finally {
        loading.value = false;
    }
}

function openAdd(): void {
    Object.assign(modal, { open: true, editing: null, name: '', active: true, error: null });
}
function openEdit(item: Lookup): void {
    Object.assign(modal, { open: true, editing: item, name: item.name, active: item.active ?? true, error: null });
}
async function save(): Promise<void> {
    modal.saving = true;
    modal.error = null;
    const payload = cfg.value.hasActive ? { name: modal.name.trim(), active: modal.active } : { name: modal.name.trim() };
    try {
        if (modal.editing) {
            await cfg.value.api.update(modal.editing.id, payload);
        } else {
            await cfg.value.api.create(payload);
        }
        modal.open = false;
        await load();
    } catch (e) {
        modal.error = apiErrorMessage(e, t('content.saveFailed'));
    } finally {
        modal.saving = false;
    }
}
async function onToggleActive(item: Lookup, value: boolean): Promise<void> {
    const prev = item.active;
    item.active = value;
    try {
        await cfg.value.api.update(item.id, { active: value });
    } catch (e) {
        item.active = prev;
        error.value = apiErrorMessage(e);
    }
}
// Row being dragged, and the order the drag started from — the fallback if the
// server refuses the new one.
const dragIndex = ref<number | null>(null);
const dragFrom = ref<Lookup[] | null>(null);

/** Move a row within the list. Local only; dropping is what saves. */
function move(from: number, to: number): void {
    if (to < 0 || to >= items.value.length || from === to) {
        return;
    }
    const list = items.value.slice();
    const [row] = list.splice(from, 1);
    list.splice(to, 0, row);
    items.value = list;
}

function onDragStart(i: number): void {
    dragIndex.value = i;
    dragFrom.value = items.value;
}

// Live-reorder as the pointer passes over another row, the way the menu editor does.
function onDragOver(i: number): void {
    if (dragIndex.value === null || dragIndex.value === i) {
        return;
    }
    move(dragIndex.value, i);
    dragIndex.value = i;
}

/**
 * Dropping is what saves. The rows have already moved, so a refusal puts the
 * list back where the drag started rather than leaving the screen lying about
 * an order the server never took.
 */
async function onDragEnd(): Promise<void> {
    const previous = dragFrom.value;
    dragIndex.value = null;
    dragFrom.value = null;
    if (previous === null || previous.map((i) => i.id).join() === items.value.map((i) => i.id).join()) {
        return;
    }
    error.value = null;
    try {
        const { data } = await examRoundsApi.reorder(items.value.map((i) => i.id));
        items.value = data.data;
    } catch (e) {
        items.value = previous;
        error.value = apiErrorMessage(e, t('content.reorderFailed'));
    }
}

/** Keyboard equivalent of the drag, so the order is not mouse-only. */
async function onGripKey(i: number, delta: number): Promise<void> {
    const previous = items.value;
    move(i, i + delta);
    if (items.value === previous) {
        return;
    }
    dragFrom.value = previous;
    await onDragEnd();
}

async function remove(item: Lookup): Promise<void> {
    if (!(await confirm.ask({ message: t('content.confirmDelete', { name: item.name }) }))) {
        return;
    }
    error.value = null;
    try {
        await cfg.value.api.remove(item.id);
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e, t('content.deleteFailed'));
    }
}

watch(() => props.kind, load);
onMounted(load);
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t(cfg.title) }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('common.results', { count: items.length }) }}</p>
            </div>
            <Tooltip v-if="canManage" :text="$t(cfg.add)">
                <button type="button"
                class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover"
                @click="openAdd"><IconPlus :size="16" />{{ $t(cfg.add) }}</button>
            </Tooltip>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="relative min-h-[8rem] overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <LoadingOverlay v-if="loading" />
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ cfg.ordered ? $t('content.order') : $t('content.id') }}</th>
                        <th class="px-4 py-3">{{ $t('content.name') }}</th>
                        <th v-if="cfg.hasActive" class="px-4 py-3">{{ $t('content.active') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(item, index) in items" :key="item.id"
                        class="odd:bg-white even:bg-gray-100 hover:bg-brand-primary-soft"
                        :class="{ 'opacity-50': dragIndex === index }"
                        :draggable="cfg.ordered && canManage"
                        @dragstart="onDragStart(index)" @dragover.prevent="onDragOver(index)"
                        @dragend="onDragEnd" @drop.prevent="onDragEnd">
                        <td v-if="cfg.ordered" class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <Tooltip v-if="canManage" :text="$t('common.dragReorder')">
                                    <button type="button" class="cursor-grab text-gray-300 hover:text-gray-500 active:cursor-grabbing"
                                        :aria-label="$t('common.dragReorder')"
                                        @keydown.up.prevent="onGripKey(index, -1)" @keydown.down.prevent="onGripKey(index, 1)">
                                        <IconGripVertical :size="16" />
                                    </button>
                                </Tooltip>
                                <span class="w-4 text-gray-500">{{ index + 1 }}</span>
                            </div>
                        </td>
                        <td v-else class="px-4 py-3 text-gray-500">{{ item.id }}</td>
                        <td class="px-4 py-3 font-medium text-gray-900">{{ item.name }}</td>
                        <td v-if="cfg.hasActive" class="px-4 py-3">
                            <Tooltip :text="$t('content.toggleActive')">
                                <ToggleSwitch :model-value="item.active ?? true" :disabled="!canManage"
                                    :aria-label="$t('content.toggleActive')" @update:model-value="(v: boolean) => onToggleActive(item, v)" />
                            </Tooltip>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1.5">
                                <Tooltip v-if="canManage" :text="$t('common.edit')">
                                    <button type="button" :class="[chip, 'text-green-600']" @click="openEdit(item)"><IconPencil :size="16" /></button>
                                </Tooltip>
                                <Tooltip v-if="canManage" :text="$t('common.remove')">
                                    <button type="button" :class="[chip, 'text-red-600']" @click="remove(item)"><IconTrash :size="16" /></button>
                                </Tooltip>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!loading && items.length === 0">
                        <td :colspan="cfg.hasActive ? 4 : 3" class="px-4 py-6 text-center text-gray-400">{{ $t('content.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Add / edit modal -->
        <div v-if="modal.open" class="fixed inset-0 z-40 flex items-start justify-center bg-black/40 p-4 pt-24" @click.self="modal.open = false">
            <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
                <div class="flex items-center justify-between rounded-t-lg bg-slate-800 px-5 py-3 text-white">
                    <h2 class="text-lg font-semibold">{{ modal.editing ? $t('content.edit') : $t(cfg.add) }}</h2>
                    <Tooltip :text="$t('common.close')" position="bottom">
                        <button type="button" class="text-white/80 hover:text-white"
                            :aria-label="$t('common.close')" @click="modal.open = false">✕</button>
                    </Tooltip>
                </div>
                <form class="flex flex-col gap-4 p-5" @submit.prevent="save">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('content.name') }}</label>
                        <input v-model="modal.name" type="text" required class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div v-if="cfg.hasActive" class="flex items-center gap-3">
                        <ToggleSwitch v-model="modal.active" :aria-label="$t('content.active')" />
                        <span class="text-sm text-gray-700">{{ $t('content.active') }}</span>
                    </div>
                    <p v-if="modal.error" class="text-sm text-red-600">{{ modal.error }}</p>
                    <div class="flex items-center justify-between border-t border-gray-200 pt-4">
                        <button type="button" class="rounded-md border border-gray-300 bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200" @click="modal.open = false">{{ $t('common.cancel') }}</button>
                        <button type="submit" :disabled="modal.saving" class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50">
                            {{ modal.saving ? $t('common.saving') : $t('common.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</template>
