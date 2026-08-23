<script setup lang="ts">
import type { Component } from 'vue';
import { IconTableExport } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import Tooltip from '@/components/Tooltip.vue';

/**
 * The standard export button. One brand colour (`brand-accent`) for every export
 * across the app, so exports read as one consistent action. Defaults to the
 * .xlsx icon/label; pass `icon`/`label` for other formats (e.g. PDF).
 */
const props = withDefaults(
    defineProps<{ disabled?: boolean; loading?: boolean; label?: string; tooltip?: string; icon?: Component; small?: boolean }>(),
    { disabled: false, loading: false, label: '', tooltip: '', icon: undefined, small: false }
);

defineEmits<{ (e: 'click'): void }>();

const { t } = useI18n();
</script>

<template>
    <!-- The label already names the action, so the tooltip carries the longer
         explanation callers pass; without one there is nothing to add. -->
    <Tooltip :text="tooltip">
        <button
            type="button"
            :disabled="disabled || loading"
            class="inline-flex items-center rounded-md bg-brand-accent font-medium text-brand-on-primary hover:bg-brand-accent-hover disabled:opacity-40"
            :class="props.small ? 'gap-1 px-2.5 py-1 text-xs' : 'gap-1.5 px-3 py-1.5 text-sm'"
            @click="$emit('click')"
        >
            <component :is="icon || IconTableExport" :size="props.small ? 14 : 16" />
            {{ label || t('common.export') }}
        </button>
    </Tooltip>
</template>
