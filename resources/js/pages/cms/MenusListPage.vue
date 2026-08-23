<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconPlus } from '@tabler/icons-vue';
import { useConfirmStore } from '@/stores/confirm';
import { listMenus, createMenu, deleteMenu } from '@/api/menus';
import { apiErrorMessage } from '@/api/http';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import RowActions from '@/components/RowActions.vue';
import Tooltip from '@/components/Tooltip.vue';
import type { CmsMenu } from '@/types/models';

/**
 * The menus themselves. There is no filtering here — a site has a handful, not
 * a list worth searching — so the screen is the table and the new-menu modal.
 */
const { t } = useI18n();
const confirm = useConfirmStore();

const rows = ref<CmsMenu[]>([]);
const loading = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
const modalOpen = ref(false);
const newName = ref('');

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await listMenus();
        rows.value = data.data;
    } catch (e) {
        error.value = apiErrorMessage(e, t('cms.menu.error'));
    } finally {
        loading.value = false;
    }
}

async function submit(): Promise<void> {
    saving.value = true;
    try {
        await createMenu(newName.value);
        modalOpen.value = false;
        newName.value = '';
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e, t('cms.menu.saveFailed'));
    } finally {
        saving.value = false;
    }
}

async function remove(menu: CmsMenu): Promise<void> {
    if (!(await confirm.ask({ message: t('cms.menu.confirmDelete', { name: menu.name }) }))) {
        return;
    }
    try {
        await deleteMenu(menu.id);
        await load();
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
}

onMounted(load);

const field = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm';
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $t('cms.menu.title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('cms.menu.subtitle') }}</p>
            </div>
            <Tooltip :text="$t('cms.menu.add')">
                <button type="button"
                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover"
                    @click="modalOpen = true">
                    <IconPlus :size="16" />{{ $t('cms.menu.add') }}
                </button>
            </Tooltip>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="relative min-h-[8rem] overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <LoadingOverlay v-if="loading" />
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">{{ $t('cms.menu.one') }}</th>
                        <th class="px-4 py-3">{{ $t('cms.menu.handle') }}</th>
                        <th class="px-4 py-3 text-center">{{ $t('cms.menu.itemCount') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <tr v-for="row in rows" :key="row.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <RouterLink :to="{ name: 'cms.menus.edit', params: { id: row.id } }"
                                class="font-medium text-gray-900 hover:text-brand-primary">{{ row.name }}</RouterLink>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ row.slug }}</td>
                        <td class="px-4 py-3 text-center tabular-nums text-gray-600">{{ row.items_count ?? 0 }}</td>
                        <td class="px-4 py-3 text-right">
                            <RowActions :edit-to="{ name: 'cms.menus.edit', params: { id: row.id } }" deletable @delete="remove(row)" />
                        </td>
                    </tr>
                    <tr v-if="!loading && rows.length === 0">
                        <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-400">{{ $t('cms.menu.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="modalOpen" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" @click.self="modalOpen = false">
            <div class="relative w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                <LoadingOverlay v-if="saving" :message="$t('common.saving')" />
                <h2 class="text-lg font-semibold">{{ $t('cms.menu.add') }}</h2>

                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.menu.name') }} *</label>
                        <input v-model="newName" type="text" required maxlength="255" :class="field" />
                        <p class="mt-1 text-xs text-gray-500">{{ $t('cms.menu.nameHint') }}</p>
                    </div>

                    <div class="flex items-center justify-between border-t border-gray-200 pt-4">
                        <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50"
                            @click="modalOpen = false">{{ $t('common.cancel') }}</button>
                        <button type="submit" :disabled="saving"
                            class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50">
                            {{ $t('common.create') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</template>
