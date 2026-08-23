<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { getPublicPage } from '@/api/publicContent';
import NotFoundPage from '@/pages/NotFoundPage.vue';
import type { PublicPage } from '@/types/models';

/**
 * A CMS page at the root of the site (`/about`). This route is the last one the
 * router tries, so an address that is not a published page falls through to the
 * ordinary not-found screen rather than showing an empty shell.
 */
const route = useRoute();

const page = ref<PublicPage | null>(null);
const notFound = ref(false);
const loading = ref(true);

async function load(): Promise<void> {
    loading.value = true;
    notFound.value = false;
    try {
        const { data } = await getPublicPage(String(route.params.slug));
        page.value = data.data;
    } catch {
        notFound.value = true;
    } finally {
        loading.value = false;
    }
}

watch(() => route.params.slug, () => void load());
onMounted(load);
</script>

<template>
    <NotFoundPage v-if="notFound" />

    <article v-else-if="page" class="mx-auto max-w-3xl space-y-6">
        <h1 class="text-3xl font-semibold leading-tight tracking-tight">{{ page.title }}</h1>
        <!-- eslint-disable-next-line vue/no-v-html -- admin-authored WYSIWYG content -->
        <div v-if="page.body" class="cms-content" v-html="page.body" />
    </article>

    <p v-else-if="loading" class="text-sm text-gray-400">{{ $t('common.loading') }}</p>
</template>
