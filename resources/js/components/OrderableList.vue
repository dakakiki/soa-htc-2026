<script setup lang="ts" generic="T extends { id: number }">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconGripVertical, IconArrowUp, IconArrowDown, IconTrash, IconPlus } from '@tabler/icons-vue';
import Tooltip from '@/components/Tooltip.vue';

const props = withDefaults(
    defineProps<{
        modelValue: T[];
        emptyText: string;
        /** Off when removing a row means more than dropping it from the list (e.g. a
         *  guarded server delete) — the caller then supplies its own action. */
        removable?: boolean;
        /** What to print in the number column. Defaults to the row's place in the
         *  list; a caller whose list holds more than one kind of thing can number
         *  only some of them, or none. */
        label?: (item: T, index: number) => string;
    }>(),
    { removable: true },
);
const emit = defineEmits<{ 'update:modelValue': [T[]] }>();

const { t } = useI18n();

// Index of the row currently being dragged (for the dimmed style).
const dragIndex = ref<number | null>(null);

function move(from: number, to: number): void {
    if (to < 0 || to >= props.modelValue.length || from === to) {
        return;
    }
    const list = props.modelValue.slice();
    const [item] = list.splice(from, 1);
    list.splice(to, 0, item);
    emit('update:modelValue', list);
}

function removeAt(i: number): void {
    const list = props.modelValue.slice();
    list.splice(i, 1);
    emit('update:modelValue', list);
}

function onDragStart(i: number): void {
    dragIndex.value = i;
}

// Live-reorder as the pointer passes over another row.
function onDragOver(i: number): void {
    if (dragIndex.value === null || dragIndex.value === i) {
        return;
    }
    move(dragIndex.value, i);
    dragIndex.value = i;
}

function onDragEnd(): void {
    dragIndex.value = null;
}
</script>

<template>
    <ol class="space-y-2">
        <li v-for="(item, i) in modelValue" :key="item.id" draggable="true"
            class="flex items-center gap-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm"
            :class="{ 'opacity-50': dragIndex === i }"
            @dragstart="onDragStart(i)" @dragover.prevent="onDragOver(i)" @dragend="onDragEnd" @drop.prevent="onDragEnd">
            <Tooltip :text="t('common.dragReorder')">
                <span class="shrink-0 cursor-grab text-gray-300 hover:text-gray-500 active:cursor-grabbing" :aria-label="t('common.dragReorder')">
                    <IconGripVertical :size="16" />
                </span>
            </Tooltip>
            <span class="w-6 shrink-0 text-center text-xs font-medium text-gray-400">{{ label ? label(item, i) : i + 1 }}</span>
            <div class="flex min-w-0 flex-1 items-center gap-2">
                <slot name="item" :item="item" :index="i" />
            </div>
            <!-- Reordering and acting on a row are different jobs, so they sit in
                 separated groups rather than one undifferentiated strip of icons. -->
            <div class="flex shrink-0 items-center gap-1 border-l border-gray-200 pl-3">
                <Tooltip :text="t('common.moveUp')">
                    <button type="button" class="text-gray-400 hover:text-gray-700 disabled:opacity-30" :disabled="i === 0"
                        :aria-label="t('common.moveUp')" @click="move(i, i - 1)"><IconArrowUp :size="16" /></button>
                </Tooltip>
                <Tooltip :text="t('common.moveDown')">
                    <button type="button" class="text-gray-400 hover:text-gray-700 disabled:opacity-30" :disabled="i === modelValue.length - 1"
                        :aria-label="t('common.moveDown')" @click="move(i, i + 1)"><IconArrowDown :size="16" /></button>
                </Tooltip>
            </div>
            <div class="flex shrink-0 items-center gap-2 border-l border-gray-200 pl-3">
                <!-- Caller's actions (view, edit, …); remove stays last, as everywhere else. -->
                <slot name="actions" :item="item" :index="i" />
                <Tooltip v-if="removable" :text="t('common.remove')">
                    <button type="button" class="text-red-600 hover:text-red-700"
                        :aria-label="t('common.remove')" @click="removeAt(i)"><IconTrash :size="16" /></button>
                </Tooltip>
            </div>
        </li>
        <li v-if="modelValue.length === 0" class="flex items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-3 text-sm text-gray-400">
            <IconPlus :size="16" /> {{ emptyText }}
        </li>
    </ol>
</template>
