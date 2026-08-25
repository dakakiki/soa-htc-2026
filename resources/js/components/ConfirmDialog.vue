<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconAlertTriangle, IconTrash } from '@tabler/icons-vue';
import { useConfirmStore } from '@/stores/confirm';

const { t } = useI18n();
const store = useConfirmStore();

function onKeydown(e: KeyboardEvent): void {
    if (store.open && e.key === 'Escape') {
        store.cancel();
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <!-- Two variants. The neutral one asks; the danger one warns, and is
             reserved for actions that destroy data and cannot be undone. Clicking
             the backdrop cancels the neutral dialog but NOT the danger one — a stray
             click should not be able to dismiss the last thing standing between the
             admin and an irreversible operation. -->
        <div
            v-if="store.open"
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
            :class="store.danger ? 'bg-black/60' : 'bg-black/40'"
            @click.self="store.danger ? null : store.cancel()"
        >
            <div
                class="w-full overflow-hidden rounded-lg bg-white shadow-xl"
                :class="store.danger ? 'max-w-lg border-2 border-red-600' : 'max-w-sm'"
            >
                <div
                    class="flex items-center gap-2 px-5 py-4"
                    :class="store.danger ? 'bg-red-600 text-white' : 'border-b border-gray-100'"
                >
                    <span :class="store.danger ? 'text-white' : 'text-red-600'">
                        <IconAlertTriangle v-if="store.danger" :size="22" />
                        <IconTrash v-else :size="20" />
                    </span>
                    <h2
                        class="font-semibold"
                        :class="store.danger
                            ? 'text-base uppercase tracking-wide'
                            : 'text-base text-gray-900'"
                    >{{ store.title || t('confirm.title') }}</h2>
                </div>
                <!-- `whitespace-pre-line` so a warning can be written as several
                     lines instead of one wall of text. -->
                <div
                    class="whitespace-pre-line px-5 py-4"
                    :class="store.danger ? 'text-sm font-medium text-red-700' : 'text-sm text-gray-600'"
                >{{ store.message }}</div>
                <!-- Danger footer pushes the two apart: backing out sits at the far
                     left, away from the button that cannot be taken back. -->
                <div
                    class="flex gap-2 px-5 py-4"
                    :class="store.danger
                        ? 'justify-between border-t border-red-200 bg-red-50'
                        : 'justify-end border-t border-gray-100'"
                >
                    <button
                        type="button"
                        class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-50"
                        @click="store.cancel"
                    >{{ store.danger ? t('common.cancel') : t('common.back') }}</button>
                    <button
                        type="button"
                        class="rounded-md px-4 py-2 text-sm font-medium text-white"
                        :class="store.danger
                            ? 'bg-green-600 uppercase tracking-wide hover:bg-green-700'
                            : 'bg-red-600 hover:bg-red-700'"
                        @click="store.confirm"
                    >{{ store.confirmLabel || t('confirm.confirm') }}</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
