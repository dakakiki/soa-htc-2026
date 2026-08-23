<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconUpload, IconSearch } from '@tabler/icons-vue';
import { listMedia, uploadMedia } from '@/api/media';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import type { CmsMedia } from '@/types/models';

/**
 * Pick a file from the library, or drop a new one in without leaving the form.
 * Used by the editor's image button and anywhere else a stored image is wanted.
 */
const emit = defineEmits<{ (e: 'close'): void; (e: 'select', media: CmsMedia): void }>();

const { t } = useI18n();

const items = ref<CmsMedia[]>([]);
const page = ref(1);
const lastPage = ref(1);
const search = ref('');
const loading = ref(false);
const uploading = ref(false);
const error = ref<string | null>(null);

async function load(target = 1): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listMedia({ page: target, per_page: 24, search: search.value || undefined });
        items.value = data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
    } catch (e) {
        error.value = apiErrorMessage(e);
    } finally {
        loading.value = false;
    }
}

async function onFiles(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    if (files.length === 0) {
        return;
    }
    uploading.value = true;
    error.value = null;
    try {
        await uploadMedia(files);
        await load(1);
    } catch (e) {
        error.value = apiErrorMessage(e, t('cms.media.uploadFailed'));
    } finally {
        uploading.value = false;
        input.value = '';
    }
}

onMounted(() => load(1));
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="emit('close')">
        <div class="relative flex max-h-[85vh] w-full max-w-4xl flex-col rounded-lg bg-white shadow-xl">
            <LoadingOverlay v-if="uploading" :message="$t('cms.media.uploading')" />

            <div class="flex items-center gap-3 border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-semibold">{{ $t('cms.media.pick') }}</h2>

                <div class="ml-auto flex items-center gap-2">
                    <div class="relative">
                        <IconSearch :size="16" class="pointer-events-none absolute left-2 top-2 text-gray-400" />
                        <input v-model="search" type="search" :placeholder="$t('cms.media.search')"
                            class="w-48 rounded-md border border-gray-300 py-1.5 pl-8 pr-3 text-sm"
                            @keyup.enter="load(1)" />
                    </div>
                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover">
                        <IconUpload :size="16" />
                        {{ $t('cms.media.upload') }}
                        <input type="file" accept="image/*" multiple class="hidden" @change="onFiles" />
                    </label>
                </div>
            </div>

            <div class="relative min-h-[12rem] flex-1 overflow-y-auto p-6">
                <LoadingOverlay v-if="loading" />

                <p v-if="error" class="mb-3 text-sm text-red-600">{{ error }}</p>

                <p v-if="!loading && items.length === 0" class="py-10 text-center text-sm text-gray-400">
                    {{ $t('cms.media.empty') }}
                </p>

                <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                    <button v-for="item in items" :key="item.id" type="button"
                        class="group overflow-hidden rounded-md border border-gray-200 text-left transition hover:border-brand-primary"
                        @click="emit('select', item)">
                        <span class="block h-24 w-full bg-gray-100 bg-cover bg-center"
                            :style="{ backgroundImage: `url(${item.url})` }" />
                        <span class="block truncate px-2 py-1 text-xs text-gray-600">{{ item.alt || item.original_name }}</span>
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between border-t border-gray-200 px-6 py-3">
                <div v-if="lastPage > 1" class="flex items-center gap-3 text-sm">
                    <button :disabled="page <= 1" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="load(page - 1)">
                        {{ $t('common.previous') }}
                    </button>
                    <span class="text-gray-500">{{ $t('common.pageOf', { current: page, last: lastPage }) }}</span>
                    <button :disabled="page >= lastPage" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="load(page + 1)">
                        {{ $t('common.next') }}
                    </button>
                </div>
                <span v-else />

                <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50" @click="emit('close')">
                    {{ $t('common.cancel') }}
                </button>
            </div>
        </div>
    </div>
</template>
