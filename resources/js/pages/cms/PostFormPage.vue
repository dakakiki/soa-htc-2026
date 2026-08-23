<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconUpload } from '@tabler/icons-vue';
import {
    createCmsPost, getCmsPost, updateCmsPost, listCmsCategories, deleteCmsPostImage,
    type CmsPostPayload,
} from '@/api/cms';
import { apiErrorMessage } from '@/api/http';
import ButtonGroup from '@/components/ButtonGroup.vue';
import ImageThumb from '@/components/ImageThumb.vue';
import LoadingOverlay from '@/components/LoadingOverlay.vue';
import MultiSelect, { type MultiSelectOption } from '@/components/MultiSelect.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import type { CmsCategory } from '@/types/models';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const id = computed(() => (route.params.id ? Number(route.params.id) : null));
const isEdit = computed(() => id.value !== null);

const form = reactive({
    title: '',
    slug: '',
    excerpt: '',
    body: '',
    status: 'draft',
    published_at: '',
    seo_title: '',
    seo_description: '',
    category_ids: [] as number[],
});

const categories = ref<CmsCategory[]>([]);
const imageFile = ref<File | null>(null);
const currentImageUrl = ref<string | null>(null);
const saving = ref(false);
const error = ref<string | null>(null);

const categoryOptions = computed<MultiSelectOption[]>(() => categories.value.map((c) => ({ id: c.id, label: c.name })));
const statusOptions = computed(() => [
    { value: 'published', label: t('cms.published'), activeClass: 'bg-green-500 text-white' },
    { value: 'draft', label: t('cms.draft'), activeClass: 'bg-gray-400 text-white' },
]);

function onFileChange(event: Event): void {
    imageFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

async function removeImage(): Promise<void> {
    if (id.value === null) {
        return;
    }
    try {
        const { data } = await deleteCmsPostImage(id.value);
        currentImageUrl.value = data.data.image_url;
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
}

function goBack(): void {
    if (window.history.length > 1) {
        router.back();
    } else {
        void router.push({ name: 'cms.posts' });
    }
}

async function submit(): Promise<void> {
    saving.value = true;
    error.value = null;

    const payload: CmsPostPayload = {
        title: form.title,
        slug: form.slug || null,
        excerpt: form.excerpt || null,
        body: form.body || null,
        status: form.status,
        // Blank means "now" when publishing, and the server keeps the old date
        // when a published post goes back to draft.
        published_at: form.published_at || null,
        seo_title: form.seo_title || null,
        seo_description: form.seo_description || null,
        category_ids: form.category_ids,
    };

    try {
        if (isEdit.value && id.value !== null) {
            await updateCmsPost(id.value, payload, imageFile.value);
        } else {
            await createCmsPost(payload, imageFile.value);
        }
        goBack();
    } catch (e) {
        error.value = apiErrorMessage(e, t('cms.post.saveFailed'));
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        const { data } = await listCmsCategories({ per_page: 200 });
        categories.value = data.data;
    } catch {
        // the category picker is optional
    }

    if (isEdit.value && id.value !== null) {
        try {
            const { data } = await getCmsPost(id.value);
            const p = data.data;
            form.title = p.title;
            form.slug = p.slug;
            form.excerpt = p.excerpt ?? '';
            form.body = p.body ?? '';
            form.status = p.status;
            // The datetime-local input wants "YYYY-MM-DDTHH:mm".
            form.published_at = p.published_at ? p.published_at.slice(0, 16) : '';
            form.seo_title = p.seo_title ?? '';
            form.seo_description = p.seo_description ?? '';
            form.category_ids = (p.categories ?? []).map((c) => c.id);
            currentImageUrl.value = p.image_url;
        } catch (e) {
            error.value = apiErrorMessage(e);
        }
    }
});

const field = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm';
const fileBtn =
    'mt-1 flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-600 hover:border-blue-400 hover:bg-brand-primary-soft';
</script>

<template>
    <section class="space-y-5">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <RouterLink :to="{ name: 'cms.posts' }" class="hover:text-gray-900">{{ $t('cms.post.title') }}</RouterLink>
            <span>/</span>
            <span class="text-gray-900">{{ isEdit ? $t('cms.post.edit') : $t('cms.post.add') }}</span>
        </div>

        <h1 class="text-2xl font-semibold tracking-tight">{{ isEdit ? $t('cms.post.edit') : $t('cms.post.add') }}</h1>

        <form class="relative rounded-lg border border-gray-200 bg-white p-6" @submit.prevent="submit">
            <LoadingOverlay v-if="saving" :message="$t('common.saving')" />
            <div class="grid grid-cols-1 gap-8 lg:grid-cols-12">
                <!-- Right column: publication, filing and the search-engine text -->
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
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.categories') }}</label>
                        <div class="mt-1">
                            <MultiSelect v-model="form.category_ids" :options="categoryOptions"
                                :placeholder="$t('cms.post.pickCategories')" :search-placeholder="$t('cms.categories')" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.post.image') }}</label>
                        <label :class="fileBtn">
                            <IconUpload :size="16" />
                            <span class="truncate">{{ imageFile?.name || $t('cms.post.chooseImage') }}</span>
                            <input type="file" accept="image/*" class="hidden" @change="onFileChange" />
                        </label>
                        <div v-if="currentImageUrl && !imageFile" class="mt-2">
                            <ImageThumb :src="currentImageUrl" :alt="form.title" @remove="removeImage" />
                        </div>
                    </div>

                    <div class="border-t border-gray-200 pt-4">
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.seoTitle') }}</label>
                        <input v-model="form.seo_title" type="text" maxlength="255" :class="field"
                            :placeholder="form.title" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.seoDescription') }}</label>
                        <textarea v-model="form.seo_description" rows="3" maxlength="500" :class="field"></textarea>
                        <p class="mt-1 text-xs text-gray-500">{{ $t('cms.seoHint') }}</p>
                    </div>
                </div>

                <!-- Left column: the article itself -->
                <div class="space-y-5 lg:col-span-8">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.postTitle') }} *</label>
                        <input v-model="form.title" type="text" required maxlength="255" :class="field" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.slug') }}</label>
                        <input v-model="form.slug" type="text" maxlength="191" :class="field" :placeholder="$t('cms.slugAuto')" />
                        <p class="mt-1 text-xs text-gray-500">{{ $t('cms.slugHint') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.excerpt') }}</label>
                        <textarea v-model="form.excerpt" rows="3" maxlength="2000" :class="field"></textarea>
                        <p class="mt-1 text-xs text-gray-500">{{ $t('cms.excerptHint') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ $t('cms.body') }}</label>
                        <div class="mt-1">
                            <RichTextEditor v-model="form.body" />
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
    </section>
</template>
