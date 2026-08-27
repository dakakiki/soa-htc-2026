<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { menuTargets } from '@/api/menus';
import SearchSelect, { type SearchSelectOption } from '@/components/SearchSelect.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import MediaPickerModal from '@/components/MediaPickerModal.vue';
import type { CmsMedia, LayoutButtonValue, LayoutRegistry } from '@/types/models';

/**
 * One button of a block (ADR-0043).
 *
 * The two switches mean different things and the labels say so: `status` is
 * whether this button may ever be seen, `gate` is a season condition on top of
 * it. Out of season a gated button is hidden however the switch is set — the
 * hint under the field is there so nobody reads the switch as a promise.
 *
 * A gated button also gets a third field: the line that stands in its place once
 * the season closes. Left empty the button just disappears, which is what it did
 * before there was anywhere to write the reason (2026-08-27).
 */
const props = defineProps<{
    modelValue: LayoutButtonValue;
    registry: LayoutRegistry;
}>();

const emit = defineEmits<{ 'update:modelValue': [LayoutButtonValue] }>();

const value = computed(() => props.modelValue);

function patch(part: Partial<LayoutButtonValue>): void {
    emit('update:modelValue', { ...value.value, ...part });
}

function patchTarget(part: Partial<LayoutButtonValue['target']>): void {
    patch({ target: { ...value.value.target, ...part } });
}

/** page / post / category are looked up; the rest are typed or picked. */
const NEEDS_LOOKUP = ['page', 'post', 'category'];
const needsLookup = computed(() => NEEDS_LOOKUP.includes(value.value.target.type));
const isFile = computed(() => value.value.target.type === 'file');
const isRoute = computed(() => value.value.target.type === 'route');

const targets = ref<SearchSelectOption[]>([]);
const targetsLoading = ref(false);
const pickerOpen = ref(false);

async function loadTargets(): Promise<void> {
    if (!needsLookup.value) {
        targets.value = [];
        return;
    }
    targetsLoading.value = true;
    try {
        const { data } = await menuTargets(value.value.target.type);
        targets.value = data.data.map((x) => ({ id: x.id, label: x.label, sub: x.slug }));
    } catch {
        targets.value = [];
    } finally {
        targetsLoading.value = false;
    }
}

watch(() => value.value.target.type, loadTargets, { immediate: true });

function onMediaSelected(media: CmsMedia): void {
    pickerOpen.value = false;
    patchTarget({ id: media.id, value: media.original_name });
}

const field = 'w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-brand-primary focus:outline-none';
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="block">
            <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('layout.button.label') }}</span>
            <input :value="value.label" type="text" maxlength="80" :class="field"
                @input="patch({ label: ($event.target as HTMLInputElement).value })" />
        </label>

        <label class="block">
            <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('layout.button.style') }}</span>
            <select :value="value.style" :class="field" @change="patch({ style: ($event.target as HTMLSelectElement).value })">
                <option v-for="style in registry.button_styles" :key="style" :value="style">
                    {{ $t(`layout.styles.${style}`) }}
                </option>
            </select>
        </label>

        <label class="block">
            <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('layout.button.targetType') }}</span>
            <select :value="value.target.type" :class="field"
                @change="patchTarget({ type: ($event.target as HTMLSelectElement).value, id: null, value: null })">
                <option v-for="type in registry.target_types" :key="type" :value="type">
                    {{ $t(`layout.targets.${type}`) }}
                </option>
            </select>
        </label>

        <!-- SearchSelect renders a button, so it never goes inside a <label>:
             the forwarded click would reopen the dropdown right after a choice. -->
        <div v-if="needsLookup">
            <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('layout.button.target') }}</span>
            <SearchSelect :model-value="value.target.id" :options="targets" :loading="targetsLoading"
                :placeholder="$t('layout.button.targetPlaceholder')"
                @update:model-value="patchTarget({ id: $event as number | null })" />
        </div>

        <div v-else-if="isFile">
            <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('layout.button.file') }}</span>
            <button type="button" :class="field + ' text-left'" @click="pickerOpen = true">
                {{ value.target.value || $t('layout.button.chooseFile') }}
            </button>
        </div>

        <!-- A screen is chosen, never typed. The box that used to be here took
             any string beginning with a slash, so one wrong character published
             a well-styled button that led to Not Found (2026-08-27). -->
        <label v-else-if="isRoute" class="block">
            <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('layout.button.screen') }}</span>
            <select :value="value.target.value ?? ''" :class="field"
                @change="patchTarget({ value: ($event.target as HTMLSelectElement).value || null })">
                <option value="">{{ $t('layout.button.screenNone') }}</option>
                <option v-for="route in registry.routes" :key="route.value" :value="route.value">
                    {{ route.label }}
                </option>
            </select>
        </label>

        <label v-else class="block">
            <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('layout.button.address') }}</span>
            <input :value="value.target.value ?? ''" type="text" maxlength="500" :class="field"
                placeholder="https://…"
                @input="patchTarget({ value: ($event.target as HTMLInputElement).value })" />
        </label>

        <div class="sm:col-span-2 grid gap-4 sm:grid-cols-2">
            <div>
                <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('layout.button.visible') }}</span>
                <ToggleSwitch :model-value="value.status" @update:model-value="patch({ status: $event })" />
                <p class="mt-1 text-xs text-gray-400">{{ $t('layout.button.visibleHint') }}</p>
            </div>

            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('layout.button.gate') }}</span>
                <select :value="value.gate ?? ''" :class="field"
                    @change="patch({ gate: ($event.target as HTMLSelectElement).value || null })">
                    <option value="">{{ $t('layout.button.gateNone') }}</option>
                    <option v-for="gate in registry.gates" :key="gate" :value="gate">
                        {{ $t(`layout.gates.${gate}`) }}
                    </option>
                </select>
                <p class="mt-1 text-xs text-gray-400">{{ $t('layout.button.gateHint') }}</p>
            </label>

            <!-- Only offered once a gate is chosen: without one the season never
                 closes this button and the line would never be shown. -->
            <label v-if="value.gate" class="block sm:col-span-2">
                <span class="mb-1 block text-sm font-medium text-gray-700">{{ $t('layout.button.closedNote') }}</span>
                <input :value="value.closed_note ?? ''" type="text" maxlength="160" :class="field"
                    :placeholder="$t('layout.button.closedNotePlaceholder')"
                    @input="patch({ closed_note: ($event.target as HTMLInputElement).value || null })" />
                <p class="mt-1 text-xs text-gray-400">{{ $t('layout.button.closedNoteHint') }}</p>
            </label>
        </div>
    </div>

    <!-- A `file` target hands something over rather than showing it, so the
         picker offers documents (ADR-0053). -->
    <MediaPickerModal v-if="pickerOpen" kind="document" @close="pickerOpen = false" @select="onMediaSelected" />
</template>
