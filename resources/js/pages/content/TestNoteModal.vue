<script setup lang="ts">
import { ref, watch } from 'vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import Tooltip from '@/components/Tooltip.vue';

/**
 * Writing a note that sits between the questions of a test.
 *
 * The text is edited here rather than in the list, so a note's ROW looks exactly
 * like a question's row (owner, 2026-08-28) — a builder full of open editors
 * stops looking like the paper it is composing.
 *
 * `body` doubles as the open/closed switch: null is closed.
 */
const props = defineProps<{ body: string | null }>();
const emit = defineEmits<{ close: []; save: [string] }>();

// A draft, so cancelling leaves the note as it was.
const draft = ref('');

watch(
    () => props.body,
    (body) => {
        draft.value = body ?? '';
    },
    { immediate: true },
);
</script>

<template>
    <div v-if="body !== null" class="fixed inset-0 z-40 flex items-start justify-center bg-black/40 p-4 pt-10"
        @click.self="emit('close')">
        <div class="relative flex max-h-[85vh] w-full max-w-2xl flex-col rounded-lg bg-white shadow-xl">
            <div class="flex items-center justify-between rounded-t-lg bg-slate-800 px-5 py-3 text-white">
                <h2 class="text-sm font-semibold uppercase tracking-widest">{{ $t('test.noteHeading') }}</h2>
                <Tooltip :text="$t('common.close')" position="bottom">
                    <button type="button" class="text-white/80 hover:text-white"
                        :aria-label="$t('common.close')" @click="emit('close')">✕</button>
                </Tooltip>
            </div>

            <div class="overflow-y-auto px-6 py-5">
                <p class="mb-3 text-sm text-gray-500">{{ $t('test.addNoteHint') }}</p>
                <RichTextEditor v-model="draft" :placeholder="$t('test.notePlaceholder')" />
            </div>

            <div class="flex justify-end gap-2 border-t border-gray-200 px-6 py-4">
                <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    @click="emit('close')">{{ $t('common.cancel') }}</button>
                <button type="button" class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-white hover:opacity-90"
                    @click="emit('save', draft)">{{ $t('common.save') }}</button>
            </div>
        </div>
    </div>
</template>
