<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconPlus } from '@tabler/icons-vue';
import { useConfirmStore } from '@/stores/confirm';
import {
    listCmsCategories, createCmsCategory, updateCmsCategory, deleteCmsCategory,
    type CmsCategoryPayload,
} from '@/api/cms';
import { apiErrorMessage } from '@/api/http';
import ButtonGroup from '@/components/ButtonGroup.vue';
import RowActions from '@/components/RowActions.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import Tooltip from '@/components/Tooltip.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import type { CmsCategory } from '@/types/models';

/**
 * Categories are small enough to live on one screen: the list plus a modal,
 * the same shape as Countries → Regions.
 */
const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const confirm = useConfirmStore();

const asString = (v: unknown): string => (typeof v === 'string' ? v : '');
const asNumber = (v: unknown): number | null => (v ? Number(v) : null);

const rows = ref<CmsCategory[]>([]);
const all = ref<CmsCategory[]>([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
const formError = ref<string | null>(null);

const editing = ref<CmsCategory | null>(null);
const modalOpen = ref(false);

const filters = reactive({
    search: asString(route.query.search),
    status: asString(route.query.status),
});

const form = reactive({
    name: '',
    slug: '',
    parent_id: null as number | null,
    description: '',
    status: 'active',
    position: 0,
});

const statusOptions = computed(() => [
    { value: 'active', label: t('cms.category.statusActive'), activeClass: 'bg-green-500 text-white' },
    { value: 'inactive', label: t('cms.category.statusInactive'), activeClass: 'bg-gray-400 text-white' },
]);

/** Any category but the one being edited may be its parent. */
const parentOptions = computed(() => all.value.filter((c) => c.id !== editing.value?.id));

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
        const { data } = await listCmsCategories({
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
        error.value = apiErrorMessage(e, t('cms.category.error'));
    } finally {
        loading.value = false;
    }
}

/** The parent picker needs every category, not just the page on screen. */
async function loadAll(): Promise<void> {
    try {
        const { data } = await listCmsCategories({ per_page: 200 });
        all.value = data.data;
    } catch {
        all.value = [];
    }
}

function open(category: CmsCategory | null): void {
    editing.value = category;
    formError.value = null;
    form.name = category?.name ?? '';
    form.slug = category?.slug ?? '';
    form.parent_id = category?.parent_id ?? null;
    form.description = category?.description ?? '';
    form.status = category?.status ?? 'active';
    form.position = category?.position ?? 0;
    modalOpen.value = true;
}

async function submit(): Promise<void> {
    saving.value = true;
    formError.value = null;

    const payload: CmsCategoryPayload = {
        name: form.name,
        slug: form.slug || null,
        parent_id: form.parent_id,
        description: form.description || null,
        status: form.status,
        position: form.position,
    };

    try {
        if (editing.value) {
            await updateCmsCategory(editing.value.id, payload);
        } else {
            await createCmsCategory(payload);
        }
        modalOpen.value = false;
        await Promise.all([load(), loadAll()]);
    } catch (e) {
        formError.value = apiErrorMessage(e, t('cms.category.saveFailed'));
    } finally {
        saving.value = false;
    }
}

async function onToggleStatus(row: CmsCategory, value: boolean): Promise<void> {
    const previous = row.status;
    row.status = value ? 'active' : 'inactive';
    try {
        await updateCmsCategory(row.id, { status: row.status });
    } catch (e) {
        row.status = previous;
        error.value = apiErrorMessage(e);
    }
}

async function remove(row: CmsCategory): Promise<void> {
    if (!(await confirm.ask({ message: t('cms.category.confirmDelete', { name: row.name }) }))) {
        return;
    }
    try {
        await deleteCmsCategory(row.id);
        await Promise.all([load(), loadAll()]);
    } catch (e) {
        // A category still holding posts comes back as 422 with its own message.
        error.value = apiErrorMessage(e);
    }
}

onMounted(async () => {
    await Promise.all([load(asNumber(route.query.page) ?? 1), loadAll()]);
});

const field = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm';
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('cms.category.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('common.total', { count: total }) }}</p>
            </div>
            <Tooltip :text="$t('cms.category.add')">
                <button type="button"
                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover"
                    @click="open(null)">
                    <IconPlus :size="16" />{{ $t('cms.category.add') }}
                </button>
            </Tooltip>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <form class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="load(1)">
                <input v-model="filters.search" type="search" :placeholder="$t('cms.category.searchPlaceholder')"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-1" />
                <select v-model="filters.status" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm lg:col-start-2" @change="load(1)">
                    <option value="">{{ $t('cms.filterStatus') }}</option>
                    <option value="active">{{ $t('cms.category.statusActive') }}</option>
                    <option value="inactive">{{ $t('cms.category.statusInactive') }}</option>
                </select>
            </form>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="relative min-h-[8rem] overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <LoadingOverlay v-if="loading" />
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ $t('cms.category.one') }}</th>
                        <th class="px-4 py-3">{{ $t('cms.slug') }}</th>
                        <th class="px-4 py-3">{{ $t('cms.category.parent') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('cms.post.title') }}</th>
                        <th class="px-4 py-3">{{ $t('cms.category.statusActive') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <tr v-for="row in rows" :key="row.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <button type="button" class="font-medium text-gray-900 hover:text-brand-primary" @click="open(row)">
                                {{ row.name }}
                            </button>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ row.slug }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ row.parent?.name ?? $t('common.dash') }}</td>
                        <td class="px-4 py-3 text-center tabular-nums text-gray-600">{{ row.posts_count ?? 0 }}</td>
                        <td class="px-4 py-3">
                            <ToggleSwitch :model-value="row.status === 'active'"
                                @update:model-value="(v: boolean) => onToggleStatus(row, v)" />
                        </td>
                        <td class="px-4 py-3 text-right">
                            <RowActions deletable @delete="remove(row)" />
                        </td>
                    </tr>
                    <tr v-if="!loading && rows.length === 0">
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-400">{{ $t('cms.category.empty') }}</td>
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

        <!-- Add / edit -->
        <div v-if="modalOpen" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" @click.self="modalOpen = false">
            <div class="relative w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
                <LoadingOverlay v-if="saving" :message="$t('common.saving')" />
                <h2 class="text-lg font-semibold">{{ editing ? $t('cms.category.edit') : $t('cms.category.add') }}</h2>

                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.category.name') }} *</label>
                        <input v-model="form.name" type="text" required maxlength="255" :class="field" />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('cms.slug') }}</label>
                            <input v-model="form.slug" type="text" maxlength="191" :class="field" :placeholder="$t('cms.slugAuto')" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('cms.category.position') }}</label>
                            <input v-model.number="form.position" type="number" min="0" :class="field" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.category.parent') }}</label>
                        <select v-model="form.parent_id" :class="field">
                            <option :value="null">{{ $t('cms.category.noParent') }}</option>
                            <option v-for="c in parentOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.category.description') }}</label>
                        <textarea v-model="form.description" rows="3" maxlength="2000" :class="field"></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.status') }}</label>
                        <div class="mt-2">
                            <ButtonGroup v-model="form.status" :options="statusOptions" />
                        </div>
                    </div>

                    <p v-if="formError" class="text-sm text-red-600">{{ formError }}</p>

                    <div class="flex items-center justify-between border-t border-gray-200 pt-4">
                        <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50"
                            @click="modalOpen = false">{{ $t('common.cancel') }}</button>
                        <button type="submit" :disabled="saving"
                            class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50">
                            {{ saving ? $t('common.saving') : $t('common.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</template>
