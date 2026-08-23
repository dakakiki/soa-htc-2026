<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconPlus, IconExternalLink } from '@tabler/icons-vue';
import { useConfirmStore } from '@/stores/confirm';
import { listCmsPages, deleteCmsPage, updateCmsPage } from '@/api/cms';
import { apiErrorMessage } from '@/api/http';
import RowActions from '@/components/RowActions.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import Tooltip from '@/components/Tooltip.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import type { CmsPage } from '@/types/models';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const confirm = useConfirmStore();

const asString = (v: unknown): string => (typeof v === 'string' ? v : '');
const asNumber = (v: unknown): number | null => (v ? Number(v) : null);

const rows = ref<CmsPage[]>([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);
const error = ref<string | null>(null);

const filters = reactive({
    search: asString(route.query.search),
    status: asString(route.query.status),
});

function syncUrl(p: number): void {
    const query: Record<string, string> = {};
    if (filters.search) query.search = filters.search;
    if (filters.status) query.status = filters.status;
    if (p > 1) query.page = String(p);
    router.replace({ query });
}

async function load(target = page.value): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listCmsPages({
            page: target,
            per_page: 10,
            search: filters.search || undefined,
            status: filters.status || undefined,
        });
        rows.value = data.data;
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;
        syncUrl(page.value);
    } catch (e) {
        error.value = apiErrorMessage(e, t('cms.page.error'));
    } finally {
        loading.value = false;
    }
}

async function onToggleStatus(row: CmsPage, value: boolean): Promise<void> {
    const previous = row.status;
    row.status = value ? 'published' : 'draft';
    try {
        const { data } = await updateCmsPage(row.id, { status: row.status });
        row.published_at = data.data.published_at;
    } catch (e) {
        row.status = previous;
        error.value = apiErrorMessage(e);
    }
}

async function remove(row: CmsPage): Promise<void> {
    if (!(await confirm.ask({ message: t('cms.page.confirmDelete', { title: row.title }) }))) {
        return;
    }
    try {
        await deleteCmsPage(row.id);
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
}

const formatDate = (iso: string | null): string => (iso ? new Date(iso).toLocaleDateString() : t('common.dash'));

onMounted(() => load(asNumber(route.query.page) ?? 1));
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('cms.page.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('common.total', { count: total }) }}</p>
            </div>
            <Tooltip :text="$t('cms.page.add')">
                <RouterLink :to="{ name: 'cms.pages.new' }"
                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover">
                    <IconPlus :size="16" />{{ $t('cms.page.add') }}
                </RouterLink>
            </Tooltip>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <form class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="load(1)">
                <input v-model="filters.search" type="search" :placeholder="$t('cms.page.searchPlaceholder')"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-1" />
                <select v-model="filters.status" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-2" @change="load(1)">
                    <option value="">{{ $t('cms.filterStatus') }}</option>
                    <option value="published">{{ $t('cms.published') }}</option>
                    <option value="draft">{{ $t('cms.draft') }}</option>
                </select>
            </form>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="relative min-h-[8rem] overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <LoadingOverlay v-if="loading" />
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ $t('cms.page.one') }}</th>
                        <th class="px-4 py-3">{{ $t('cms.address') }}</th>
                        <th class="px-4 py-3">{{ $t('cms.publishedAt') }}</th>
                        <th class="px-4 py-3">{{ $t('cms.published') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <tr v-for="row in rows" :key="row.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <RouterLink :to="{ name: 'cms.pages.edit', params: { id: row.id } }"
                                class="font-medium text-gray-900 hover:text-brand-primary">{{ row.title }}</RouterLink>
                        </td>
                        <td class="px-4 py-3 text-gray-600">
                            <span class="inline-flex items-center gap-1">
                                {{ row.path }}
                                <Tooltip v-if="row.status === 'published'" :text="$t('cms.viewOnSite')">
                                    <a :href="row.path" target="_blank" :aria-label="$t('cms.viewOnSite')"
                                        class="text-gray-400 hover:text-brand-primary"><IconExternalLink :size="14" /></a>
                                </Tooltip>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ formatDate(row.published_at) }}</td>
                        <td class="px-4 py-3">
                            <ToggleSwitch :model-value="row.status === 'published'"
                                @update:model-value="(v: boolean) => onToggleStatus(row, v)" />
                        </td>
                        <td class="px-4 py-3 text-right">
                            <RowActions :edit-to="{ name: 'cms.pages.edit', params: { id: row.id } }" deletable @delete="remove(row)" />
                        </td>
                    </tr>
                    <tr v-if="!loading && rows.length === 0">
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">{{ $t('cms.page.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="lastPage > 1" class="flex items-center gap-3 text-sm">
            <button :disabled="page <= 1" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="load(page - 1)">
                {{ $t('common.previous') }}
            </button>
            <span class="text-gray-500">{{ $t('common.pageOf', { current: page, last: lastPage }) }}</span>
            <button :disabled="page >= lastPage" class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40" @click="load(page + 1)">
                {{ $t('common.next') }}
            </button>
        </div>
    </section>
</template>
