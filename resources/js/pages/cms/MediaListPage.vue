<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconUpload, IconTrash, IconCopy, IconCheck } from '@tabler/icons-vue';
import { useConfirmStore } from '@/stores/confirm';
import { listMedia, uploadMedia, updateMedia, deleteMedia } from '@/api/media';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import Tooltip from '@/components/Tooltip.vue';
import type { CmsMedia } from '@/types/models';

/**
 * The library itself: everything uploaded for the site, in one grid. Selecting a
 * file opens its details — alt text, address, and the delete that nothing else
 * can do for it.
 */
const { t } = useI18n();
const confirm = useConfirmStore();

const items = ref<CmsMedia[]>([]);
const selected = ref<CmsMedia | null>(null);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const search = ref('');
const loading = ref(false);
const uploading = ref(false);
const savingAlt = ref(false);
const copied = ref(false);
const error = ref<string | null>(null);

async function load(target = 1): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listMedia({ page: target, per_page: 24, search: search.value || undefined });
        items.value = data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;
    } catch (e) {
        error.value = apiErrorMessage(e, t('cms.media.error'));
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

async function saveAlt(): Promise<void> {
    if (!selected.value) {
        return;
    }
    savingAlt.value = true;
    try {
        const { data } = await updateMedia(selected.value.id, { alt: selected.value.alt || null });
        const row = items.value.find((i) => i.id === data.data.id);
        if (row) {
            row.alt = data.data.alt;
        }
    } catch (e) {
        error.value = apiErrorMessage(e);
    } finally {
        savingAlt.value = false;
    }
}

async function remove(item: CmsMedia): Promise<void> {
    if (!(await confirm.ask({ message: t('cms.media.confirmDelete', { name: item.original_name }) }))) {
        return;
    }
    try {
        await deleteMedia(item.id);
        selected.value = null;
        await load(page.value);
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
}

async function copyUrl(item: CmsMedia): Promise<void> {
    try {
        await navigator.clipboard.writeText(new URL(item.url, window.location.origin).href);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        // Clipboard access can be refused; the address is on screen either way.
    }
}

/** Bytes as something a person reads. */
function humanSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

onMounted(() => load(1));

const field = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm';
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('cms.media.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('common.total', { count: total }) }}</p>
            </div>
            <Tooltip :text="$t('cms.media.upload')">
                <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover">
                    <IconUpload :size="16" />{{ $t('cms.media.upload') }}
                    <input type="file" accept="image/*" multiple class="hidden" @change="onFiles" />
                </label>
            </Tooltip>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <form class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="load(1)">
                <input v-model="search" type="search" :placeholder="$t('cms.media.search')"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-1" />
            </form>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <div class="relative min-h-[12rem] rounded-lg border border-gray-200 bg-white p-4">
                <LoadingOverlay v-if="loading || uploading" :message="uploading ? $t('cms.media.uploading') : undefined" />

                <p v-if="!loading && items.length === 0" class="py-10 text-center text-sm text-gray-400">
                    {{ $t('cms.media.empty') }}
                </p>

                <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    <button v-for="item in items" :key="item.id" type="button"
                        class="overflow-hidden rounded-md border text-left transition"
                        :class="selected?.id === item.id ? 'border-brand-primary ring-1 ring-brand-primary' : 'border-gray-200 hover:border-gray-400'"
                        @click="selected = item">
                        <span class="block h-28 w-full bg-gray-100 bg-cover bg-center"
                            :style="{ backgroundImage: `url(${item.url})` }" />
                        <span class="block truncate px-2 py-1 text-xs text-gray-600">{{ item.original_name }}</span>
                    </button>
                </div>

                <div v-if="lastPage > 1" class="mt-4 flex items-center gap-3 text-sm">
                    <button :disabled="page <= 1" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="load(page - 1)">
                        {{ $t('common.previous') }}
                    </button>
                    <span class="text-gray-500">{{ $t('common.pageOf', { current: page, last: lastPage }) }}</span>
                    <button :disabled="page >= lastPage" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="load(page + 1)">
                        {{ $t('common.next') }}
                    </button>
                </div>
            </div>

            <!-- Details of whatever is selected. An empty rail means nothing is. -->
            <aside class="self-start rounded-lg border border-gray-200 bg-white">
                <p v-if="!selected" class="px-4 py-8 text-center text-sm text-gray-400">{{ $t('cms.media.one') }}</p>

                <div v-else class="space-y-4 p-4">
                    <img :src="selected.url" :alt="selected.alt ?? selected.original_name"
                        class="max-h-40 w-full rounded object-contain" />

                    <p class="break-all text-sm font-medium text-gray-900">{{ selected.original_name }}</p>

                    <dl class="grid grid-cols-2 gap-1 text-xs text-gray-500">
                        <dt>{{ $t('cms.media.dimensions') }}</dt>
                        <dd class="text-right tabular-nums text-gray-700">
                            {{ selected.width && selected.height ? `${selected.width} × ${selected.height}` : $t('common.dash') }}
                        </dd>
                        <dt>{{ $t('cms.media.size') }}</dt>
                        <dd class="text-right tabular-nums text-gray-700">{{ humanSize(selected.size) }}</dd>
                        <dt>{{ $t('cms.media.uploadedBy') }}</dt>
                        <dd class="truncate text-right text-gray-700">{{ selected.uploaded_by ?? $t('common.dash') }}</dd>
                    </dl>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.media.alt') }}</label>
                        <input v-model="selected.alt" type="text" maxlength="255" :class="field" @blur="saveAlt" />
                        <p class="mt-1 text-xs text-gray-500">{{ $t('cms.media.altHint') }}</p>
                    </div>

                    <div class="flex items-center justify-between border-t border-gray-200 pt-3">
                        <Tooltip :text="$t('cms.media.copyUrl')">
                            <button type="button" :aria-label="$t('cms.media.copyUrl')"
                                class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
                                @click="copyUrl(selected)">
                                <component :is="copied ? IconCheck : IconCopy" :size="16" />
                                {{ copied ? $t('cms.media.copied') : $t('cms.media.copyUrl') }}
                            </button>
                        </Tooltip>
                        <Tooltip :text="$t('common.remove')">
                            <button type="button" :aria-label="$t('common.remove')"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-gray-300 bg-gray-100 text-red-600 hover:bg-gray-200"
                                @click="remove(selected)">
                                <IconTrash :size="16" />
                            </button>
                        </Tooltip>
                    </div>

                    <p v-if="savingAlt" class="text-xs text-gray-400">{{ $t('common.saving') }}</p>
                </div>
            </aside>
        </div>
    </section>
</template>
