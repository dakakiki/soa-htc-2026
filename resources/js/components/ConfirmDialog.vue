<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconTrash } from '@tabler/icons-vue';
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
        <div
            v-if="store.open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            @click.self="store.cancel"
        >
            <div class="w-full max-w-sm overflow-hidden rounded-lg bg-white shadow-xl">
                <div class="flex items-center gap-2 border-b border-gray-100 px-5 py-4">
                    <span class="text-red-600"><IconTrash :size="20" /></span>
                    <h2 class="text-base font-semibold text-gray-900">{{ store.title || t('confirm.title') }}</h2>
                </div>
                <div class="px-5 py-4 text-sm text-gray-600">{{ store.message }}</div>
                <div class="flex justify-end gap-2 border-t border-gray-100 px-5 py-4">
                    <button
                        type="button"
                        class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50"
                        @click="store.cancel"
                    >{{ t('common.back') }}</button>
                    <button
                        type="button"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
                        @click="store.confirm"
                    >{{ t('confirm.confirm') }}</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
