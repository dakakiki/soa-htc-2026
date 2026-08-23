<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { createCmsPage, getCmsPage, updateCmsPage, type CmsPagePayload } from '@/api/cms';
import { apiErrorMessage } from '@/api/http';
import { IconPhoto } from '@tabler/icons-vue';
import ButtonGroup from '@/components/ButtonGroup.vue';
import ImageThumb from '@/components/ImageThumb.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import MediaPickerModal from '@/components/MediaPickerModal.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import type { CmsMedia } from '@/types/models';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const id = computed(() => (route.params.id ? Number(route.params.id) : null));
const isEdit = computed(() => id.value !== null);

const form = reactive({
    title: '',
    slug: '',
    body: '',
    status: 'draft',
    published_at: '',
    seo_title: '',
    seo_description: '',
    image_media_id: null as number | null,
});

const currentPath = ref<string | null>(null);
const imageUrl = ref<string | null>(null);
const showMedia = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);

const statusOptions = computed(() => [
    { value: 'published', label: t('cms.published'), activeClass: 'bg-green-500 text-white' },
    { value: 'draft', label: t('cms.draft'), activeClass: 'bg-gray-400 text-white' },
]);


/** The featured image is a reference to the library, not a new upload. */
function pickImage(media: CmsMedia): void {
    form.image_media_id = media.id;
    imageUrl.value = media.url;
    showMedia.value = false;
}

/** Clearing only unsets the reference; the file stays in the library. */
function clearImage(): void {
    form.image_media_id = null;
    imageUrl.value = null;
}

function goBack(): void {
    if (window.history.length > 1) {
        router.back();
    } else {
        void router.push({ name: 'cms.pages' });
    }
}

async function submit(): Promise<void> {
    saving.value = true;
    error.value = null;

    const payload: CmsPagePayload = {
        title: form.title,
        slug: form.slug || null,
        body: form.body || null,
        status: form.status,
        published_at: form.published_at || null,
        seo_title: form.seo_title || null,
        seo_description: form.seo_description || null,
        image_media_id: form.image_media_id,
    };

    try {
        if (isEdit.value && id.value !== null) {
            await updateCmsPage(id.value, payload);
        } else {
            await createCmsPage(payload);
        }
        goBack();
    } catch (e) {
        error.value = apiErrorMessage(e, t('cms.page.saveFailed'));
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    if (!isEdit.value || id.value === null) {
        return;
    }
    try {
        const { data } = await getCmsPage(id.value);
        const p = data.data;
        form.title = p.title;
        form.slug = p.slug;
        form.body = p.body ?? '';
        form.status = p.status;
        form.published_at = p.published_at ? p.published_at.slice(0, 16) : '';
        form.seo_title = p.seo_title ?? '';
        form.seo_description = p.seo_description ?? '';
        currentPath.value = p.path;
        form.image_media_id = p.image_media_id;
        imageUrl.value = p.image_url;
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
});

const field = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm';
const fileBtn =
    'mt-1 flex w-full cursor-pointer items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-2 text-left text-sm text-gray-600 hover:border-blue-400 hover:bg-brand-primary-soft';
</script>

<template>
    <section class="space-y-5">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <RouterLink :to="{ name: 'cms.pages' }" class="hover:text-gray-900">{{ $t('cms.page.title') }}</RouterLink>
            <span>/</span>
            <span class="text-gray-900">{{ isEdit ? $t('cms.page.edit') : $t('cms.page.add') }}</span>
        </div>

        <h1 class="text-2xl font-semibold tracking-tight">{{ isEdit ? $t('cms.page.edit') : $t('cms.page.add') }}</h1>

        <form class="relative rounded-lg border border-gray-200 bg-white p-6" @submit.prevent="submit">
            <LoadingOverlay v-if="saving" :message="$t('common.saving')" />
            <div class="grid grid-cols-1 gap-8 lg:grid-cols-12">
                <!-- Right column: publication and the search-engine text -->
                <div class="space-y-5 lg:order-2 lg:col-span-4 lg:border-l lg:border-gray-200 lg:pl-8">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.status') }}</label>
                        <div class="mt-2">
                            <ButtonGroup v-model="form.status" :options="statusOptions" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.publishedAt') }}</label>
                        <input v-model="form.published_at" type="datetime-local" :class="field" />
                        <p class="mt-1 text-xs text-gray-500">{{ $t('cms.publishedAtHint') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.image') }}</label>
                        <button type="button" :class="fileBtn" @click="showMedia = true">
                            <IconPhoto :size="16" />
                            <span class="truncate">{{ $t('cms.chooseImage') }}</span>
                        </button>
                        <div v-if="imageUrl" class="mt-2">
                            <ImageThumb :src="imageUrl" :alt="form.title" @remove="clearImage" />
                        </div>
                    </div>

                    <div v-if="currentPath" class="rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600">
                        {{ $t('cms.livesAt') }} <span class="font-mono">{{ currentPath }}</span>
                    </div>

                    <div class="border-t border-gray-200 pt-4">
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.seoTitle') }}</label>
                        <input v-model="form.seo_title" type="text" maxlength="255" :class="field" :placeholder="form.title" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.seoDescription') }}</label>
                        <textarea v-model="form.seo_description" rows="3" maxlength="500" :class="field"></textarea>
                        <p class="mt-1 text-xs text-gray-500">{{ $t('cms.seoHint') }}</p>
                    </div>
                </div>

                <!-- Left column: the page itself -->
                <div class="space-y-5 lg:col-span-8">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.pageTitle') }} *</label>
                        <input v-model="form.title" type="text" required maxlength="255" :class="field" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.slug') }}</label>
                        <input v-model="form.slug" type="text" maxlength="191" :class="field" :placeholder="$t('cms.slugAuto')" />
                        <p class="mt-1 text-xs text-gray-500">{{ $t('cms.pageSlugHint') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.body') }}</label>
                        <div class="mt-1">
                            <RichTextEditor v-model="form.body" rich min-height="min-h-[32rem]" />
                        </div>
                    </div>
                </div>
            </div>

            <p v-if="error" class="mt-4 text-sm text-red-600">{{ error }}</p>

            <div class="mt-6 flex items-center justify-between border-t border-gray-200 pt-4">
                <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50" @click="goBack">
                    {{ $t('common.cancel') }}
                </button>
                <button type="submit" :disabled="saving"
                    class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-50">
                    {{ saving ? $t('common.saving') : isEdit ? $t('common.save') : $t('common.create') }}
                </button>
            </div>
        </form>

        <MediaPickerModal v-if="showMedia" @close="showMedia = false" @select="pickImage" />
    </section>
</template>
