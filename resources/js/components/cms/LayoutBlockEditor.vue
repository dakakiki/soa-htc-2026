<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { updateLayoutBlock } from '@/api/cmsLayout';
import { apiErrorMessage } from '@/api/http';
import LayoutButtonFields from '@/components/cms/LayoutButtonFields.vue';
import MediaPickerModal from '@/components/MediaPickerModal.vue';
import ImageThumb from '@/components/ImageThumb.vue';
import type { CmsLayoutBlock, CmsMedia, LayoutButtonValue, LayoutField, LayoutRegistry, LayoutTypeInfo } from '@/types/models';

/**
 * The form for one section. Every field comes from the type's own declaration
 * (BlockSchema on the server), so the editor shows exactly what this type has
 * and nothing else — the legacy module form offered every field to every kind of
 * module and left the rest blank.
 */
const props = defineProps<{
    block: CmsLayoutBlock;
    type: LayoutTypeInfo;
    registry: LayoutRegistry;
}>();

const emit = defineEmits<{ close: []; saved: [CmsLayoutBlock] }>();

const { t } = useI18n();

// A deep copy: nothing is written back until Save.
const content = reactive<Record<string, unknown>>(JSON.parse(JSON.stringify(props.block.content ?? {})));
const imageId = ref<number | null>(props.block.image_media_id);
const imageUrl = ref<string | null>(props.block.image?.url ?? null);

const saving = ref(false);
const error = ref<string | null>(null);
const pickerOpen = ref(false);

const field = 'w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-brand-primary focus:outline-none';

function emptyButton(): LayoutButtonValue {
    return {
        label: '',
        style: props.registry.button_styles[0] ?? 'primary',
        status: true,
        gate: null,
        target: { type: 'route', id: null, value: null },
    };
}

/** A blank row for a repeatable list, with every declared key present. */
function emptyRow(item: LayoutField[]): Record<string, unknown> {
    const row: Record<string, unknown> = {};
    item.forEach((sub) => {
        row[sub.key] = sub.kind === 'button' ? emptyButton() : '';
    });
    return row;
}

function rows(key: string): Record<string, unknown>[] {
    if (!Array.isArray(content[key])) {
        content[key] = [];
    }
    return content[key] as Record<string, unknown>[];
}

function buttons(key: string): LayoutButtonValue[] {
    if (!Array.isArray(content[key])) {
        content[key] = [];
    }
    return content[key] as LayoutButtonValue[];
}

function addRow(f: LayoutField): void {
    if (f.kind === 'buttons') {
        buttons(f.key).push(emptyButton());
        return;
    }
    rows(f.key).push(emptyRow(f.item ?? []));
}

function removeRow(key: string, index: number): void {
    (content[key] as unknown[]).splice(index, 1);
}

function onMediaSelected(media: CmsMedia): void {
    pickerOpen.value = false;
    imageId.value = media.id;
    imageUrl.value = media.url;
}

async function save(): Promise<void> {
    saving.value = true;
    error.value = null;
    try {
        const { data } = await updateLayoutBlock(props.block.id, {
            status: props.block.status,
            content: JSON.parse(JSON.stringify(content)),
            image_media_id: props.type.uses_image ? imageId.value : null,
        });
        emit('saved', data.data);
    } catch (e) {
        error.value = apiErrorMessage(e, t('layout.saveFailed'));
    } finally {
        saving.value = false;
    }
}

const title = computed(() => props.type.label);
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="emit('close')">
        <div class="flex max-h-[85vh] w-full max-w-3xl flex-col rounded-lg bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-semibold tracking-tight">{{ title }}</h2>
                <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-500">{{ block.type }}</span>
            </div>

            <div class="flex flex-col gap-6 overflow-auto px-6 py-5">
                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                <div v-if="type.uses_image">
                    <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('layout.image') }}</span>
                    <button type="button"
                        class="flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-600 hover:border-brand-primary hover:bg-brand-primary-soft"
                        @click="pickerOpen = true">
                        {{ imageUrl ? $t('layout.changeImage') : $t('layout.chooseImage') }}
                    </button>
                    <div v-if="imageUrl" class="mt-2 inline-flex rounded border border-gray-200 p-2">
                        <ImageThumb :src="imageUrl" alt="" img-class="h-16 max-w-[14rem] object-contain"
                            removable @remove="imageId = null; imageUrl = null" />
                    </div>
                </div>

                <template v-for="f in type.fields" :key="f.key">
                    <label v-if="f.kind === 'text'" class="block">
                        <span class="mb-1 block text-sm font-medium text-gray-700">{{ f.label }}</span>
                        <input v-model="content[f.key] as string" type="text" :maxlength="f.max" :class="field" />
                    </label>

                    <label v-else-if="f.kind === 'textarea'" class="block">
                        <span class="mb-1 block text-sm font-medium text-gray-700">{{ f.label }}</span>
                        <textarea v-model="content[f.key] as string" rows="3" :maxlength="f.max" :class="field" />
                    </label>

                    <label v-else-if="f.kind === 'number'" class="block max-w-[12rem]">
                        <span class="mb-1 block text-sm font-medium text-gray-700">{{ f.label }}</span>
                        <input v-model.number="content[f.key] as number" type="number" :min="f.min" :max="f.max" :class="field" />
                    </label>

                    <label v-else-if="f.kind === 'enum'" class="block max-w-[16rem]">
                        <span class="mb-1 block text-sm font-medium text-gray-700">{{ f.label }}</span>
                        <select v-model="content[f.key] as string" :class="field">
                            <option v-for="option in f.options" :key="option" :value="option">{{ option }}</option>
                        </select>
                    </label>

                    <div v-else-if="f.kind === 'button'">
                        <span class="mb-2 block text-sm font-medium text-gray-700">{{ f.label }}</span>
                        <div class="rounded-lg border border-gray-200 p-4">
                            <LayoutButtonFields
                                :model-value="(content[f.key] ?? emptyButton()) as LayoutButtonValue"
                                :registry="registry"
                                @update:model-value="content[f.key] = $event" />
                        </div>
                    </div>

                    <div v-else>
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-sm font-medium text-gray-700">{{ f.label }}</span>
                            <button type="button"
                                class="inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover"
                                @click="addRow(f)">
                                {{ $t('layout.addRow') }}
                            </button>
                        </div>

                        <div class="flex flex-col gap-3">
                            <div v-for="(_, i) in (content[f.key] as unknown[] ?? [])" :key="i"
                                class="rounded-lg border border-gray-200 p-4">
                                <div class="mb-3 flex items-center justify-between">
                                    <span class="font-mono text-xs uppercase tracking-wider text-gray-400">{{ i + 1 }}</span>
                                    <button type="button" class="text-sm text-red-600 hover:underline" @click="removeRow(f.key, i)">
                                        {{ $t('common.remove') }}
                                    </button>
                                </div>

                                <LayoutButtonFields v-if="f.kind === 'buttons'"
                                    :model-value="buttons(f.key)[i]" :registry="registry"
                                    @update:model-value="buttons(f.key)[i] = $event" />

                                <div v-else class="grid gap-4 sm:grid-cols-2">
                                    <template v-for="sub in f.item ?? []" :key="sub.key">
                                        <div v-if="sub.kind === 'button'" class="sm:col-span-2 rounded-md border border-gray-200 p-3">
                                            <span class="mb-2 block text-sm font-medium text-gray-700">{{ sub.label }}</span>
                                            <LayoutButtonFields
                                                :model-value="(rows(f.key)[i][sub.key] ?? emptyButton()) as LayoutButtonValue"
                                                :registry="registry"
                                                @update:model-value="rows(f.key)[i][sub.key] = $event" />
                                        </div>
                                        <label v-else-if="sub.kind === 'textarea'" class="block sm:col-span-2">
                                            <span class="mb-1 block text-sm font-medium text-gray-700">{{ sub.label }}</span>
                                            <textarea v-model="rows(f.key)[i][sub.key] as string" rows="2" :maxlength="sub.max" :class="field" />
                                        </label>
                                        <label v-else-if="sub.kind === 'enum'" class="block">
                                            <span class="mb-1 block text-sm font-medium text-gray-700">{{ sub.label }}</span>
                                            <select v-model="rows(f.key)[i][sub.key] as string" :class="field">
                                                <option v-for="option in sub.options" :key="option" :value="option">{{ option }}</option>
                                            </select>
                                        </label>
                                        <label v-else class="block">
                                            <span class="mb-1 block text-sm font-medium text-gray-700">{{ sub.label }}</span>
                                            <input v-model="rows(f.key)[i][sub.key] as string" type="text" :maxlength="sub.max" :class="field" />
                                        </label>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <div class="flex justify-end gap-3 border-t border-gray-200 px-6 py-4">
                <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    @click="emit('close')">
                    {{ $t('common.cancel') }}
                </button>
                <button type="button" :disabled="saving"
                    class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-brand-on-primary hover:bg-brand-primary-hover disabled:opacity-60"
                    @click="save">
                    {{ saving ? $t('common.saving') : $t('common.save') }}
                </button>
            </div>
        </div>
    </div>

    <MediaPickerModal v-if="pickerOpen" @close="pickerOpen = false" @select="onMediaSelected" />
</template>
