<script setup lang="ts">
import { IconTableExport } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';

/**
 * The standard export-to-.xlsx button. One brand colour (`brand-accent`) for
 * every export across the app, so exports read as one consistent action.
 */
withDefaults(
    defineProps<{ disabled?: boolean; loading?: boolean; label?: string; tooltip?: string }>(),
    { disabled: false, loading: false, label: '', tooltip: '' }
);

defineEmits<{ (e: 'click'): void }>();

const { t } = useI18n();
</script>

<template>
    <button
        type="button"
        :disabled="disabled || loading"
        :title="tooltip || undefined"
        class="inline-flex items-center gap-1.5 rounded-md bg-brand-accent px-3 py-1.5 text-sm font-medium text-brand-on-primary hover:bg-brand-accent-hover disabled:opacity-40"
        @click="$emit('click')"
    >
        <IconTableExport :size="16" />
        {{ label || t('common.export') }}
    </button>
</template>
